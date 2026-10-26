<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
/**
 * Ad place anchors (ADS-BRIEF 8.1-8.3): the default map on the skeleton every theme shares + 18 theme profiles.
 * Resolution order (JcxPlacer and static/jcx/jcx-p.js): hook marker <div data-jcx-at="P16"></div> >
 * jcx_anchor override (admin) > theme profile > default map.
 *
 * Anchor keys: at (grammar 8.1: tag, #id, .class, [attr], [attr=value], descendant spaces, or @player /
 * @grid:n / @section-of-grid:n), pos (beforebegin|afterbegin|beforeend|afterend), nth (n-th match),
 * every / max (in-feed: insert before child every+1, 2*every+1 ...), hd / hm (reserved px without the
 * 20 px label, computer / phone), pages (page types the anchor applies to; per-page variants in 'on').
 * A profile value null switches a place off for that theme. '_ui' holds runtime hints for jcx.js
 * (5.9): bt = fixed bottom bars the dock sits above, lf = floating buttons lifted while the dock shows,
 * nav = the theme's nav list for U19 links, ic = 0 when in-feed cells must not copy the card class,
 * rs = the theme's content wrappers in order (side rails measure the gutters of the first one present on the
 * page; none = #main-content, R6-01).
 */
return [
    '_default' => [
        'P01' => ['at' => '#main-content', 'pos' => 'afterbegin', 'hd' => 90, 'hm' => 50],
        'P02' => ['at' => '@section-of-grid:1', 'pos' => 'beforebegin', 'hd' => 250, 'hm' => 100],
        'P03' => ['at' => '@player', 'pos' => 'afterend', 'hd' => 90, 'hm' => 250],
        'P03d' => ['at' => '@section-of-grid:1', 'pos' => 'beforebegin', 'hd' => 90, 'hm' => 100],
        'P05' => ['at' => '@grid:1', 'every' => 8, 'max' => 2, 'on' => ['home' => ['at' => '@grid:2', 'every' => 4, 'max' => 1]]],
        'P06' => ['at' => '@section-of-grid:3', 'pos' => 'beforebegin', 'hd' => 90, 'hm' => 250],
        'P07' => ['at' => '#main-content', 'pos' => 'afterend', 'hd' => 90, 'hm' => 100],
        'P12' => ['at' => '@grid:1', 'pos' => 'beforebegin', 'hd' => 60, 'hm' => 100],
        'P13' => ['at' => '#main-content article p', 'pos' => 'afterend', 'nth' => 3, 'hd' => 250, 'hm' => 250, 'fallback' => ['at' => '#main-content article', 'pos' => 'beforeend']],
        'P16' => ['at' => '#comments', 'pos' => 'beforebegin', 'hd' => 90, 'hm' => 250],
        '_ui' => ['bt' => '', 'lf' => '', 'nav' => ''],
    ],
    'jav' => ['_ui' => ['lf' => 'a.to-top']],
    'jav-modern' => ['_ui' => ['lf' => 'a.to-top']],
    'javcms' => ['P16' => ['at' => 'section.jc-comments'], '_ui' => ['lf' => 'a.jc-totop', 'rs' => ['div.wrap']]],
    // The default P06 / P16 anchors resolve inside the 208 px side column: off until a theme hook (12.e).
    'javcms-modern' => ['P06' => null, 'P16' => null, '_ui' => ['lf' => 'a.jc-top']],
    'ember' => ['P16' => ['at' => 'section.e-comments'], 'P05' => ['on' => ['home' => ['at' => 'section#e-latest [data-grid-fill]', 'every' => 4, 'max' => 1]]],
        '_ui' => ['bt' => 'nav.e-tabbar', 'lf' => 'div.e-quick', 'rs' => ['section.e-sec']]],
    'ember-modern' => ['P04' => ['at' => 'aside.t-heatside', 'pos' => 'afterbegin', 'hd' => 250, 'hm' => 0], 'P16' => ['at' => 'section.t-comments'], '_ui' => ['bt' => 'nav.t-dock', 'rs' => ['div.t-wrap']]],
    // Comments are a tab panel: P16 off until a theme hook (12.e).
    'onyx' => ['P16' => null, '_ui' => ['lf' => 'button.to-top']],
    'onyx-modern' => ['P04' => ['at' => 'aside.ox-queue', 'pos' => 'afterbegin', 'hd' => 250, 'hm' => 0]],
    'velvet' => ['P16' => ['at' => 'section.vv-comments'], '_ui' => ['bt' => 'nav.vv-tabbar', 'rs' => ['div.vv-wrap']]],
    'velvet-modern' => ['P16' => ['at' => 'div.mac_comment'], '_ui' => ['bt' => 'nav.vp-dock', 'rs' => ['div.vp-wrap']]],
    'honey' => ['P04' => ['at' => 'aside.hn-side', 'pos' => 'afterbegin', 'hd' => 250, 'hm' => 0], '_ui' => ['bt' => 'nav.hn-tabbar', 'lf' => 'button.hn-totop', 'rs' => ['div.hn-wrap']]],
    'honey-modern' => ['_ui' => ['lf' => 'div.nc-fab-wrap', 'rs' => ['div.nc-watch-body', 'div.nc-wrap']]],
    'neon' => ['P04' => ['at' => 'aside.nx-side', 'pos' => 'afterbegin', 'hd' => 250, 'hm' => 0], 'P16' => ['at' => '#nx-comments'], '_ui' => ['bt' => 'nav.nx-tabbar', 'lf' => 'button.nx-gotop', 'rs' => ['div.nx-wrap']]],
    'neon-modern' => ['P04' => ['at' => 'aside.nn-side', 'pos' => 'afterbegin', 'hd' => 250, 'hm' => 0], 'P16' => ['at' => '#nn-comments'], '_ui' => ['bt' => 'nav.nn-dock', 'lf' => 'button.nn-gotop', 'rs' => ['div.nn-wrap']]],
    'crimson' => ['P04' => ['at' => 'aside.ct-wside', 'pos' => 'afterbegin', 'hd' => 250, 'hm' => 0], 'P16' => ['at' => 'section.ct-comments'], '_ui' => ['bt' => 'nav.ct-dock', 'lf' => 'button.ct-totop']],
    // The home chapters have no data-grid-fill (R1-04, R4-05): P02 / P05 / P06 anchor on the chapters themselves.
    'crimson-modern' => ['P04' => ['at' => 'aside.ci-wside', 'pos' => 'afterbegin', 'hd' => 250, 'hm' => 0], 'P16' => ['at' => 'section.ci-comments'],
        'P02' => ['on' => ['home' => ['at' => 'section.ci-chapter', 'pos' => 'beforebegin', 'nth' => 1]]],
        'P05' => ['on' => ['home' => ['at' => 'div.ci-grid', 'nth' => 2, 'every' => 4, 'max' => 1]]],
        'P06' => ['at' => 'section.ci-chapter', 'pos' => 'beforebegin', 'nth' => 3],
        '_ui' => ['rs' => ['div.ci-wrap']]],
    'topaz' => ['P04' => ['at' => 'aside.tz-side', 'pos' => 'afterbegin', 'hd' => 250, 'hm' => 0], 'P16' => ['at' => 'section.tz-comments'], '_ui' => ['rs' => ['div.tz-wrap']]],
    'topaz-modern' => ['P04' => ['at' => 'aside.tp-side', 'pos' => 'afterbegin', 'hd' => 250, 'hm' => 0], 'P16' => ['at' => 'section.tp-comments'], '_ui' => ['rs' => ['div.tp-wrap']]],
];
