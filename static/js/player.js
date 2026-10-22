/*
 * This file is part of JavCMS - https://javcms.net
 * Based on MacCMS 10 (Apache-2.0) by magicblack, changed by JavCMS.
 * JavCMS changes: Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors,
 * JavCMS Community License 1.0. The MacCMS parts stay under the Apache License 2.0.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
/* Public player bootstrap. Kept compatible with the MacPlayer adapter API. */
(function (window, document) {
    'use strict';
    window.__macPlayerClean = '20261009-clean8';

    var data = window.player_aaaa || {};
    var config = window.MacPlayerConfig || {};
    var script = document.currentScript;
    var host = script && script.parentNode;
    var player = window.MacPlayer = window.MacPlayer || {};

    function decoded(value) {
        value = String(value || '');
        try {
            if (String(data.encrypt) === '2') value = window.atob(value);
            if (String(data.encrypt) === '1' || String(data.encrypt) === '2') value = decodeURIComponent(value);
        } catch (error) {
            return '';
        }
        return value;
    }

    function safeUrl(value) {
        try {
            var url = new URL(String(value || '').trim(), window.location.href);
            return url.protocol === 'https:' || url.protocol === 'http:' ? url.href : '';
        } catch (error) {
            return '';
        }
    }

    // Accessible name of the player frame: a specific title from the provider, else the theme's
    // translation (data-frame-title on a wrapper), the core's (player_aaaa.frame_title), the theme
    // messages (TemplateMessages['ui.iframeplayer']), else English. Adapters such as ngm3u8 set the
    // generic English 'Video player', which never wins over a translation.
    function frameTitle(frame, target) {
        var title = frame.getAttribute('title') || '';
        if (title === 'Video player') title = '';
        if (!title && target && target.closest) {
            var labelled = target.closest('[data-frame-title]');
            if (labelled) title = labelled.getAttribute('data-frame-title') || '';
        }
        if (!title && typeof data.frame_title === 'string') title = data.frame_title;
        if (!title) {
            var messages = window.TemplateMessages || {};
            title = messages['ui.iframeplayer'] || messages['ui.video_player'] || '';
        }
        title = String(title).trim();
        return title || 'Video player';
    }

    function message(text) {
        var box = document.createElement('p');
        box.className = 'mac-player-message';
        box.textContent = text;
        box.style.cssText = 'display:grid;place-items:center;min-height:160px;padding:24px;margin:0;color:#fff;background:#18202b;text-align:center';
        return box;
    }

    player.GetUrl = function (sid, nid) {
        return String(this.Link || '').replace(/\{sid\}/g, sid).replace(/\{nid\}/g, nid);
    };
    player.Go = function (sid, nid) {
        var url = safeUrl(this.GetUrl(sid, nid));
        if (url) window.location.assign(url);
    };
    player.Show = function () {
        if (!this.Target) return;
        var template = document.createElement('template');
        template.innerHTML = String(this.Html || '');
        var frame = template.content.querySelector('iframe,video,embed,object');
        if (!frame) {
            this.Target.replaceChildren(message('This playback format is unavailable.'));
            return;
        }
        var sourceAttribute = frame.tagName.toLowerCase() === 'object' ? 'data' : 'src';
        var url = safeUrl(frame.getAttribute(sourceAttribute));
        if (!url) {
            this.Target.replaceChildren(message('The playback link is unavailable.'));
            return;
        }
        var tag = frame.tagName.toLowerCase();
        var embedded = document.createElement(tag);
        embedded[sourceAttribute] = url;
        embedded.title = frameTitle(frame, this.Target);
        embedded.style.cssText = 'display:block;width:100%;height:100%;min-height:160px;border:0';
        if (tag === 'iframe') {
            embedded.setAttribute('allow', 'autoplay; fullscreen; picture-in-picture; encrypted-media');
            // The `fullscreen` token in `allow` is the current permission
            // mechanism. Keeping the legacy `allowfullscreen` boolean as
            // well causes Chromium to emit a duplicate-permission warning.
            // Providers (including upload18 players) verify the embedding origin and refuse
            // to run inside a sandboxed frame, so send the origin and do not sandbox.
            embedded.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
            embedded.setAttribute('loading', 'eager');
        } else if (tag === 'video') {
            embedded.controls = true;
            embedded.playsInline = true;
            embedded.setAttribute('preload', 'metadata');
        } else if (tag === 'embed') {
            embedded.type = frame.getAttribute('type') || 'application/x-shockwave-flash';
        } else {
            embedded.type = frame.getAttribute('type') || '';
        }
        this.Target.replaceChildren(embedded);
    };
    player.Init = function () {
        var started0 = Date.now();
        if (!host) return;
        var source = String(data.from || '');
        if (!/^[a-zA-Z0-9_-]+$/.test(source)) {
            host.insertBefore(message('The playback source is unavailable.'), script);
            return;
        }
        var settings = config.player_list && config.player_list[source];
        if (settings && String(settings.ps) === '1') {
            source = 'parse';
            this.Parse = String(settings.parse || config.parse || '');
        } else {
            this.Parse = '';
        }
        this.PlayUrl = decoded(data.url);
        this.PlayUrlNext = decoded(data.url_next);
        this.PlayFrom = source;
        this.PlayNote = String(data.note || '');
        this.PlayServer = String(data.server || '');
        this.Link = String(data.link || '');
        this.Id = data.id;
        this.Sid = data.sid;
        this.Nid = data.nid;
        this.Width = '100%';
        this.Height = '100%';
        this.Path = String((window.maccms && window.maccms.path) || '/').replace(/\/?$/, '/') + 'static/player/';

        var container = document.createElement('div');
        container.className = 'MacPlayer';
        container.style.cssText = 'width:100%;height:100%;min-height:160px;margin:0;background:#111';
        var target = document.createElement('div');
        target.id = 'playleft';
        target.style.cssText = 'width:100%;height:100%;min-height:160px';
        container.appendChild(target);
        host.insertBefore(container, script);
        this.Target = target;

        if (!safeUrl(this.PlayUrl)) {
            target.replaceChildren(message('The playback link is unavailable.'));
            return;
        }
        if (String(data.flag) === 'down') {
            var link = document.createElement('a');
            link.href = safeUrl(this.PlayUrl);
            link.textContent = 'Download';
            link.rel = 'noopener noreferrer';
            target.replaceChildren(link);
            return;
        }
        var path = this.Path;
        var adapterSrc = path + source + '.js?v=20261008-clean6';
        var started = false;
        function startAdapter() {
            if (started) return;
            started = true;
            var adapter = document.createElement('script');
            adapter.src = adapterSrc;
            adapter.async = false;
            adapter.onerror = function () {
                target.replaceChildren(message('This playback source is unavailable.'));
            };
            host.insertBefore(adapter, script.nextSibling);
        }
        // Video ads (pre-roll gate): jcx-v.js loads only when the page has #jcx-v (an enabled video ad) or the
        // admin preview hint cookie jcxh=1. The gate runs BEFORE the adapter creates the provider iframe and always
        // ends in startAdapter(); a load error, an exception or the 6 s budget from the player's start (the gate gets
        // the start time and keeps the same budget until its stage shows) starts the video at once.
        var jcxv = document.getElementById('jcx-v');
        if (jcxv || /(^|;\s*)jcxh=1/.test(document.cookie)) {
            var ver = '';
            try { ver = String(JSON.parse(jcxv.textContent).jv || ''); } catch (error) {}
            // The adapter script downloads while the gate runs (VO-04: on a slow line its request after the gate added
            // about 0.6 s before the provider iframe); it still runs only in startAdapter(), after the gate.
            var preload = document.createElement('link');
            preload.rel = 'preload';
            preload.as = 'script';
            preload.href = adapterSrc;
            document.head.appendChild(preload);
            var timer = setTimeout(startAdapter, Math.max(500, started0 + 6000 - Date.now()));
            var runtime = document.createElement('script');
            runtime.src = path.replace(/player\/$/, 'jcx/jcx-v.js?v=') + encodeURIComponent(ver);
            runtime.onload = function () {
                clearTimeout(timer);
                if (started) return;
                try { window.JcxV.gate(target, data, startAdapter, started0); } catch (error) { startAdapter(); }
            };
            runtime.onerror = startAdapter;
            document.head.appendChild(runtime);
        } else {
            startAdapter();
        }
    };

    player.Init();
}(window, document));
