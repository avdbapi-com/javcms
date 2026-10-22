<?php
/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
namespace app\index\controller;

/**
 * Public XML-only endpoint; deliberately avoids frontend user/session rendering (SEO-ADMIN-BRIEF E.1).
 * sitemap.xml[?feed=pages|videos|actors|topics|articles&bucket=<n>[&lang=<code>]]: 404 for anything that does not
 * exist (unknown feed, bucket past the last one, a lang that is not an indexed non-default language, CF12) and for
 * every sitemap URL while the site is hidden from search engines (seo.hide_site, CF02). Answers gzip with
 * Content-Encoding when the client accepts it.
 */
class Sitemap extends \think\Controller
{
    public function index()
    {
        $query = input('get.', [], '');
        $part = $query['feed'] ?? 'index';
        if (!is_string($part) || !in_array($part, ['index','pages','videos','actors','topics','articles'], true)) {
            return response('Invalid sitemap', 404)->header($this->cacheHeaders());
        }
        return $this->feed($part);
    }
    public function videos() { return $this->feed('videos'); }
    public function pages() { return $this->feed('pages'); }
    public function actors() { return $this->feed('actors'); }
    public function topics() { return $this->feed('topics'); }
    public function articles() { return $this->feed('articles'); }

    protected function cacheHeaders($cacheable = false)
    {
        if (!$cacheable) { return ['Cache-Control'=>'no-store', 'Expires'=>'Thu, 01 Jan 1970 00:00:00 GMT']; }
        $seconds = \app\common\util\Sitemap::CACHE_SECONDS;
        return ['Cache-Control'=>'public, max-age=' . $seconds . ', must-revalidate',
            'Expires'=>gmdate('D, d M Y H:i:s', time() + $seconds) . ' GMT'];
    }

    protected function feed($part)
    {
        // The "txt" URL suffix exists for /robots.txt only; sitemaps answer as .xml.
        if (preg_match('/\.txt$/i', (string)$this->request->pathinfo())) { return response('Invalid sitemap', 404)->header($this->cacheHeaders()); }
        if (empty($GLOBALS['config']['site']['site_status'])) { return response('Site unavailable', 503)->header(array_merge($this->cacheHeaders(), ['Retry-After'=>'300'])); }
        try {
            $parameters = input('param.', [], '');
            $bucket = null;
            if (in_array($part, ['videos','actors','topics','articles'], true)) {
                $bucket = \app\common\util\Sitemap::validBucket($parameters['bucket'] ?? null);
                if ($bucket === null) { return response('Invalid sitemap', 404)->header($this->cacheHeaders()); }
            }
            $lang = array_key_exists('lang', $parameters) ? $parameters['lang'] : null;
            if ($lang !== null && !is_string($lang)) { return response('Invalid sitemap', 404)->header($this->cacheHeaders()); }
            $payload = \app\common\util\Sitemap::payload($part, $bucket, $lang);
            if ($payload === null) { return response('Invalid sitemap', 404)->header($this->cacheHeaders()); }
            $headers = ['Content-Type'=>'application/xml; charset=UTF-8', 'X-Content-Type-Options'=>'nosniff', 'Vary'=>'Accept-Encoding'];
            if (isset($payload['gz']) && \app\common\util\Sitemap::acceptsGzip((string)$this->request->header('accept-encoding'))) {
                $body = $payload['gz'];
                $headers['Content-Encoding'] = 'gzip';
            } else {
                $body = \app\common\util\Sitemap::xmlOf($payload);
            }
            return response($body, 200, array_merge($headers, $this->cacheHeaders(true)));
        } catch (\Throwable $error) {
            // Never submit an HTML error page or a partially generated sitemap as valid XML.
            \think\Log::record('Sitemap generation failed: ' . get_class($error), 'error');
            return response('Sitemap temporarily unavailable', 503)->header(array_merge($this->cacheHeaders(), ['Retry-After'=>'300']));
        }
    }
}
