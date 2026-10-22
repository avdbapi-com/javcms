/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
/*
 * Render provider playback in an iframe.  The old implementation
 * concatenated an iframe string directly from MacPlayer.PlayUrl.  That made
 * malformed or hostile imported URLs part of the page markup.  Keep the
 * legacy MacPlayer API, but build
 * the element with DOM APIs and reject non-http(s) URLs first.
 */
(function () {
    var rawUrl = String(MacPlayer.PlayUrl || '').trim();
    var providerUrl;
    try {
        providerUrl = new URL(rawUrl, window.location.href);
    } catch (error) {
        return;
    }
    if (providerUrl.protocol !== 'https:' && providerUrl.protocol !== 'http:') {
        return;
    }

    var frame = document.createElement('iframe');
    frame.width = '100%';
    frame.height = String(MacPlayer.Height || '100%');
    frame.src = providerUrl.href;
    frame.title = 'Video player';
    frame.setAttribute('frameborder', '0');
    frame.setAttribute('scrolling', 'no');
    frame.setAttribute('allow', 'autoplay; fullscreen; picture-in-picture; encrypted-media');
    // The provider player verifies the embedding origin and refuses to run in a
    // sandboxed frame, so send the origin and leave the frame unsandboxed.
    frame.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
    frame.setAttribute('loading', 'eager');

    var wrapper = document.createElement('div');
    wrapper.appendChild(frame);
    MacPlayer.Html = wrapper.innerHTML;
    MacPlayer.Show();
}());
