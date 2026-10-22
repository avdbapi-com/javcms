/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
/* Store watch history by canonical local video ID, independent of UI title. */
$(document).ready(function () {
    if (typeof vod_id === 'undefined' || typeof vod_name === 'undefined') { return; }
    var entries = [];
    try { entries = JSON.parse($.cookie('recente') || '[]'); } catch (error) { entries = []; }
    if (!Array.isArray(entries)) { entries = []; }
    var url = new URL(vod_url, window.location.href);
    url.searchParams.delete('ui_lang');
    var current = {vod_id: Number(vod_id), vod_name: String(vod_name), vod_url: url.href, vod_part: String(vod_part)};
    entries = entries.filter(function (entry) {
        if (!entry || typeof entry !== 'object') { return false; }
        return Number(entry.vod_id) !== current.vod_id && entry.vod_url !== current.vod_url;
    });
    entries.unshift(current);
    $.cookie('recente', JSON.stringify(entries.slice(0, 10)), {path: '/', expires: 2});
    if (typeof stui !== 'undefined' && stui.common && typeof stui.common.history === 'function') {
        $('#stui_history').empty();
        stui.common.history();
    }
});
