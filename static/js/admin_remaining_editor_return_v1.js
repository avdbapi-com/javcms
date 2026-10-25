/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
/* admin-remaining-editor-return-v1 */
(function (window, $) {
    'use strict';
    if (!$) return;
    $(function () {
        $(document).on('click', '[data-admin-remaining-editor-return]', function (event) {
            event.preventDefault();
            var href = $(this).attr('href');
            try {
                if (window.parent && window.parent !== window && window.parent.layer) {
                    var parentLayer = window.parent.layer;
                    var frameIndex = typeof parentLayer.getFrameIndex === 'function' ? parentLayer.getFrameIndex(window.name) : null;
                    var validFrameIndex = (typeof frameIndex === 'number' && isFinite(frameIndex) && frameIndex >= 0) || (typeof frameIndex === 'string' && /^(0|[1-9]\d*)$/.test(frameIndex));
                    if (validFrameIndex && typeof parentLayer.close === 'function') {
                        parentLayer.close(frameIndex);
                        return false;
                    }
                }
            } catch (error) {
                // Workspace and inaccessible parent frames follow the existing Return URL.
            }
            if (href) window.location.href = href;
            return false;
        });
    });
}(window, window.jQuery));
