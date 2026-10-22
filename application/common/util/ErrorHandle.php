<?php
/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
namespace app\common\util;

use think\App;
use think\Config;
use think\Request;
use think\Response;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;

/**
 * Public error responses (config "exception_handle"). Visitors never see framework internals.
 *
 * - Debug mode (app_debug, or a signed theme preview cookie plus jv_theme_debug=1, see
 *   behavior/Init.php) keeps ThinkPHP's detailed page.
 * - Front end (index.php): HTTP 404 renders the active theme's public/404.html with status 404
 *   (JSON for AJAX/JSON clients); every other error gets a small self-contained page without
 *   exception text.
 * - API (api.php): JSON {"code":status,"msg":...} with the HTTP status, never an HTML page.
 * - Admin and installer keep their previous behaviour (message without trace); other entrances
 *   get the generic configured message.
 */
class ErrorHandle extends Handle
{
    public function render(\Exception $e)
    {
        if (App::$debug || (defined('IS_CLI') && IS_CLI)) {
            // Debug mode keeps the detailed page for real errors. A URL that does not exist still shows
            // the theme's 404 page (theme previews check that page); the reason travels in a header.
            if (App::$debug && !(defined('IS_CLI') && IS_CLI) && $e instanceof HttpException
                && (int) $e->getStatusCode() === 404 && !self::wantsJson()) {
                self::discardOutput();
                $themed = self::themedNotFound();
                if ($themed instanceof Response) {
                    return $themed->header(['X-Debug-Not-Found' => self::headerText($e->getMessage())]);
                }
            }
            return parent::render($e);
        }
        $entrance = defined('ENTRANCE') ? (string) ENTRANCE : '';
        if ($entrance === 'admin' || $entrance === 'install') {
            Config::set('show_error_msg', true);
            return parent::render($e);
        }
        if ($entrance === 'api') {
            $status = $e instanceof HttpException ? (int) $e->getStatusCode() : 500;
            if ($status < 400 || $status > 599) {
                $status = 500;
            }
            self::discardOutput();
            $response = self::json($status);
            if ($e instanceof HttpException && $e->getHeaders()) {
                $response->header($e->getHeaders());
            }
            return $response;
        }
        if ($entrance !== 'index') {
            return parent::render($e);
        }
        // 1.2.0 (owner decision 2026-10-17, verify r2 R2-10): a statement time limit, or the spent request deadline, anywhere
        // in a front-end request answers with the page's stale copy, else the lighter page (HTTP 200, Retry-After), never
        // this error page.
        if (!($e instanceof HttpException) && class_exists('\\app\\common\\util\\DbTimeout') && \app\common\util\DbTimeout::is($e)
            && class_exists('\\app\\common\\util\\EdgeCache')) {
            \app\common\util\EdgeCache::shed();
        }
        $status = $e instanceof HttpException ? (int) $e->getStatusCode() : 500;
        if ($status === 404) {
            return self::notFound();
        }
        if ($status < 400 || $status > 599) {
            $status = 500;
        }
        $response = self::failure($status);
        if ($e instanceof HttpException && $e->getHeaders()) {
            $response->header($e->getHeaders());
        }
        return $response;
    }

    /** Themed 404 for requests that never reached a controller (unknown controller, bad route). */
    public static function notFound()
    {
        self::discardOutput();
        if (self::wantsJson()) {
            return self::json(404);
        }
        $themed = self::themedNotFound();
        return $themed instanceof Response ? $themed : self::failure(404);
    }

    /** The active theme's public/404 page (MyError controller, HTTP 404), or null when it cannot render. */
    private static function themedNotFound()
    {
        if (!defined('ENTRANCE') || ENTRANCE !== 'index' || !defined('MAC_ROOT_TEMPLATE')
            || !class_exists('\\app\\index\\controller\\MyError')) {
            return null;
        }
        try {
            // A 404 raised while routing (ThinkPHP "invalid request" for a URL aimed at a named route,
            // e.g. /vod/detail/id/1.html) comes before the module is set: model('Group') and friends would
            // resolve to app\model\* and fail. Front-end 404s belong to the index module.
            $request = Request::instance();
            if ((string) $request->module() === '') {
                $request->module('index');
            }
            $page = new \app\index\controller\MyError();
            $response = $page->_empty();
            return $response instanceof Response ? $response : null;
        } catch (HttpResponseException $redirect) {
            return $redirect->getResponse();
        } catch (\Throwable $error) {
            self::log('Themed 404', $error);
            return null;
        }
    }

    /** Printable ASCII only (no header injection, no raw UTF-8 bytes), at most 200 characters. */
    private static function headerText($value)
    {
        return substr(trim((string) preg_replace('/[^\x20-\x7E]+/', '?', (string) $value)), 0, 200);
    }

    /** True for XMLHttpRequest calls and clients that ask for JSON rather than HTML. */
    public static function wantsJson()
    {
        try {
            $request = Request::instance();
            if ($request->isAjax(true)) {
                return true;
            }
            $accept = strtolower((string) $request->header('accept'));
        } catch (\Throwable $error) {
            return false;
        }
        return $accept !== '' && strpos($accept, 'application/json') !== false && strpos($accept, 'text/html') === false;
    }

    public static function json($status, $message = '')
    {
        $status = (int) $status;
        if (!is_string($message) || $message === '') {
            $message = $status === 404
                ? self::text('ui.page_not_found_bc3023', 'Page not found')
                : self::text('request_failed', 'Unable to complete the request. Please try again.');
        }
        return Response::create(['code' => $status, 'msg' => $message], 'json', $status)->header(self::noStore());
    }

    /** Minimal, self-contained error page: no theme, no database, no exception details. */
    public static function failure($status = 500)
    {
        $status = (int) $status;
        self::discardOutput();
        if (self::wantsJson()) {
            return self::json($status);
        }
        $notFound = $status === 404;
        $heading = $notFound ? self::text('ui.page_not_found_bc3023', 'Page not found')
            : self::text('request_failed', 'Unable to complete the request. Please try again.');
        $home = self::text('ui.back_to_home', 'Back to home');
        $lang = 'en';
        $dir = 'ltr';
        try {
            $lang = (string) TemplateText::locale();
            $dir = TemplateText::direction() === 'rtl' ? 'rtl' : 'ltr';
        } catch (\Throwable $error) {
            // Keep the English defaults.
        }
        $path = defined('MAC_PATH') && is_string(MAC_PATH) && MAC_PATH !== '' && MAC_PATH[0] === '/' ? MAC_PATH : '/';
        $e = function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $html = '<!DOCTYPE html><html lang="' . $e($lang) . '" dir="' . $dir . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow"><meta name="color-scheme" content="dark light">'
            . '<title>' . $status . ' - ' . $e($heading) . '</title><style>'
            . 'html,body{height:100%;margin:0}body{display:flex;align-items:center;justify-content:center;padding:24px;box-sizing:border-box;'
            . 'background:#0f1115;color:#e9ebf0;font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;text-align:center}'
            . 'main{max-width:520px}.code{margin:0;font-size:72px;font-weight:800;line-height:1;letter-spacing:.04em;color:#ff8a3d}'
            . 'h1{margin:16px 0 24px;font-size:22px;font-weight:600}a{display:inline-block;padding:10px 20px;border-radius:999px;'
            . 'background:#ff8a3d;color:#111;text-decoration:none;font-weight:600}a:focus-visible{outline:3px solid #fff;outline-offset:3px}'
            . '@media (prefers-color-scheme:light){body{background:#f6f7f9;color:#1b1d22}.code{color:#d4570f}a{background:#d4570f;color:#fff}}'
            . '</style></head><body><main><p class="code">' . $status . '</p><h1>' . $e($heading) . '</h1>'
            . '<a href="' . $e($path) . '">' . $e($home) . '</a></main></body></html>';
        return Response::create($html, 'html', $status)->header(self::noStore());
    }

    private static function noStore()
    {
        return ['Cache-Control' => 'no-store, max-age=0', 'X-Robots-Tag' => 'noindex'];
    }

    private static function text($key, $fallback)
    {
        try {
            if (class_exists('\\app\\common\\util\\TemplateText')) {
                $value = TemplateText::raw($key);
                if (is_string($value) && trim($value) !== '' && $value !== $key) {
                    return $value;
                }
            }
        } catch (\Throwable $error) {
            // Catalog trouble must never hide the error page itself.
        }
        return $fallback;
    }

    private static function discardOutput()
    {
        while (ob_get_level() > 1) {
            @ob_end_clean();
        }
        if (ob_get_level() === 1) {
            @ob_clean();
        }
    }

    private static function log($context, \Throwable $error)
    {
        try {
            \think\Log::record($context . ' failed: ' . get_class($error) . ': ' . $error->getMessage()
                . ' @' . basename($error->getFile()) . ':' . $error->getLine(), 'error');
        } catch (\Throwable $ignored) {
            // Logging is best effort here.
        }
    }
}
