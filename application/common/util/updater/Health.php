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
namespace app\common\util\updater;

/**
 * Health check after an update (UPDATER decision U3): the home page, one list page, one detail page and the admin answer
 * without a server error or a PHP error message.
 *
 * The probes go to the site's own address (the one the admin used; on the command line --url or site_url) with the
 * run's secret probe header, which the maintenance gate lets through, and a cache-busting query, so they meet the NEW
 * code, not the maintenance page or a cached copy. When the arming request told the server's own address, the probes go
 * there first (curl pins the site's host name to it: same Host and TLS name, no CDN in between). List and detail pages
 * are taken from the links of the home page, so every URL style and theme works. Certificates are verified; for the
 * site's own host only, a certificate that does not verify (a local or panel certificate) is accepted, because nothing
 * secret but the one-run probe header is sent.
 *
 * An answer that refuses the server itself rather than showing a broken page (401 / 403 / 407 / 429 / 503: HTTP basic
 * auth on the admin, an IP allow-list, a CDN or firewall challenge, a proxy that drops the probe header) does not count
 * as broken: when every failed probe was refused, the result says "refused" and the run lets the admin's browser check
 * the pages (it has the credentials, the allowed address and the CDN's clearance).
 */
final class Health
{
    /** Texts that mean PHP or the framework failed on a page. */
    const MARKERS = array('Fatal error', 'Parse error', 'Uncaught ', 'Stack trace:', '<b>Warning</b>:', '<b>Notice</b>:',
        '<b>Deprecated</b>:', 'Something went wrong. Please try again later.', 'think\\exception', 'ThinkPHP V5');
    /** Statuses that refuse the request instead of showing a broken page. */
    const REFUSED = array(401, 403, 407, 429, 503);

    /**
     * Runs the checks. $base: "scheme://host[:port]/sub/" of the site, $admin: admin entry file name, $probe: the run's
     * probe secret, $origin: the server's own IP address (or ''). Returns ['ok', 'checks' => [['name', 'path', 'status',
     * 'ok', 'why', 'k', 'v', 'ms', 'refused']], 'error', 'unreachable' (this server does not reach itself), 'refused'
     * (every failed probe was refused)].
     */
    public static function run($base, $admin, $probe, $deadline, $origin = '')
    {
        $base = rtrim((string)$base, '/') . '/';
        if (!preg_match('#^https?://[^/]+/#i', $base)) {
            return array('ok' => false, 'checks' => array(array('name' => 'home', 'path' => '', 'status' => 0, 'ok' => false,
                'why' => 'the site address is not known', 'k' => 'hr_noaddress', 'v' => array(), 'ms' => 0, 'refused' => false)),
                'error' => 'the site address is not known', 'unreachable' => true, 'refused' => false);
        }
        $checks = array();
        // the home page decides quickly whether this server answers its own requests at all (one-worker servers do not)
        $home = self::fetch($base . '?jcupd_probe=' . Util::randomId(4), $probe, min($deadline, microtime(true) + 8.0), array(), $origin);
        $checks[] = self::judge('home', '/', $home);
        $links = $home['status'] === 200 ? self::links($home['body'], $base) : array('list' => '', 'detail' => '');
        foreach (array('list', 'detail') as $kind) {
            if (microtime(true) >= $deadline) {
                $checks[] = array('name' => $kind, 'path' => '', 'status' => 0, 'ok' => false, 'why' => 'out of time', 'k' => 'hr_timeout', 'v' => array(), 'ms' => 0, 'refused' => false);
                continue;
            }
            if ($links[$kind] === '') {
                $checks[] = array('name' => $kind, 'path' => '', 'status' => 0, 'ok' => true, 'why' => 'no ' . $kind . ' link on the home page (skipped)',
                    'k' => 'hr_skipped', 'v' => array(), 'ms' => 0, 'refused' => false);
                continue;
            }
            $u = $links[$kind];
            $r = self::fetch($u . (strpos($u, '?') === false ? '?' : '&') . 'jcupd_probe=' . Util::randomId(4), $probe, $deadline, array(), $origin);
            $checks[] = self::judge($kind, self::path($u, $base), $r);
        }
        $login = self::fetch($base . $admin . '/admin/index/login.html', $probe, $deadline, array(), $origin);
        $c = self::judge('admin', $admin, $login);
        if ($c['ok'] && strpos($login['body'], 'admin_name') === false) {
            $c['ok'] = false;
            $c['why'] = 'the admin sign-in page has no sign-in form';
            $c['k'] = 'hr_noform';
            $c['v'] = array();
        }
        $checks[] = $c;
        $ok = true;
        $failed = 0;
        $refused = 0;
        foreach ($checks as $x) {
            $ok = $ok && $x['ok'];
            if (!$x['ok']) {
                $failed++;
                $refused += !empty($x['refused']) ? 1 : 0;
            }
        }
        // connection-level failure of the home page and the admin: this server does not answer requests it makes to itself
        $unreachable = $home['status'] === 0 && $login['status'] === 0;
        return array('ok' => $ok, 'checks' => $checks, 'error' => $ok ? '' : 'health check failed', 'unreachable' => $unreachable,
            'refused' => !$ok && $failed > 0 && $failed === $refused);
    }

    private static function path($url, $base)
    {
        return '/' . ltrim(substr($url, strlen(rtrim($base, '/'))), '/');
    }

    /** Verdict of one probe. */
    private static function judge($name, $path, array $r)
    {
        $ok = $r['status'] === 200;
        $refused = in_array($r['status'], self::REFUSED, true);
        if ($ok) {
            $why = '';
            $k = '';
            $v = array();
        } elseif ($r['status'] === 0) {
            $why = $r['error'] !== '' ? $r['error'] : 'no answer';
            $k = 'hr_conn';
            $v = array('error' => $why);
        } elseif ($r['error'] === 'redirected to another host') {
            $why = $r['error'];
            $k = 'hr_redirect';
            $v = array();
        } else {
            $why = 'HTTP ' . $r['status'] . ($refused ? ' (refused)' : '');
            $k = $refused ? 'hr_refused' : 'hr_http';
            $v = array('status' => $r['status']);
        }
        if ($ok) {
            foreach (self::MARKERS as $m) {
                if (stripos($r['body'], $m) !== false) {
                    $ok = false;
                    $why = 'error text on the page: ' . $m;
                    $k = 'hr_marker';
                    $v = array('marker' => $m);
                    break;
                }
            }
        }
        return array('name' => $name, 'path' => $path, 'status' => $r['status'], 'ok' => $ok, 'why' => $why, 'k' => $k, 'v' => $v, 'ms' => $r['ms'],
            'refused' => !$ok && $refused);
    }

    /**
     * Verdict of the checks the admin's browser ran and reported (runner action "health"): the same rules as run(). Each
     * reported check: name, status, body flags (marker = an error text the page contained, form = the admin page has the
     * sign-in form). Returns ['ok', 'checks', 'error'].
     */
    public static function judgeReport(array $report)
    {
        $checks = array();
        $names = array();
        foreach (array_slice($report, 0, 8) as $c) {
            if (!is_array($c)) {
                continue;
            }
            $name = preg_replace('/[^a-z]/', '', (string)($c['name'] ?? ''));
            $status = (int)($c['status'] ?? 0);
            $marker = Util::cut((string)($c['marker'] ?? ''), 80);
            $skipped = !empty($c['skipped']) && in_array($name, array('list', 'detail'), true);
            $ok = $skipped || ($status === 200 && $marker === '' && ($name !== 'admin' || !empty($c['form'])));
            if ($ok) {
                $why = $skipped ? 'no ' . $name . ' link on the home page (skipped)' : '';
                $k = $skipped ? 'hr_skipped' : '';
                $v = array();
            } elseif ($status !== 200) {
                $why = $status === 0 ? 'no answer' : 'HTTP ' . $status;
                $k = $status === 0 ? 'hr_conn' : 'hr_http';
                $v = $status === 0 ? array('error' => 'no answer') : array('status' => $status);
            } elseif ($marker !== '') {
                $why = 'error text on the page: ' . $marker;
                $k = 'hr_marker';
                $v = array('marker' => $marker);
            } else {
                $why = 'the admin sign-in page has no sign-in form';
                $k = 'hr_noform';
                $v = array();
            }
            $checks[] = array('name' => $name, 'path' => Util::cut((string)($c['path'] ?? ''), 200), 'status' => $status, 'ok' => $ok,
                'why' => $why . ' (checked by the browser)', 'k' => $k, 'v' => $v, 'browser' => true, 'ms' => (int)($c['ms'] ?? 0));
            $names[$name] = true;
        }
        $ok = isset($names['home'], $names['admin']);
        foreach ($checks as $c) {
            $ok = $ok && $c['ok'];
        }
        return array('ok' => $ok, 'checks' => $checks, 'error' => $ok ? '' : 'health check failed', 'browser' => true);
    }

    /**
     * The failed checks of result $h: [English text "home: HTTP 500; admin: ...", list of ['name', 'k', 'v', 'browser']
     * for the admin page, which shows them in the admin's language].
     */
    public static function reasons(array $h)
    {
        $text = array();
        $list = array();
        foreach ((array)($h['checks'] ?? array()) as $c) {
            if (!empty($c['ok'])) {
                continue;
            }
            $text[] = $c['name'] . ': ' . $c['why'];
            $list[] = array('name' => (string)$c['name'], 'k' => (string)($c['k'] ?? ''), 'v' => (array)($c['v'] ?? array()), 'browser' => !empty($c['browser']) ? 1 : 0);
        }
        return array(implode('; ', $text), array_slice($list, 0, 6));
    }

    /** The first list link and the first detail link of a page (same host only). */
    public static function links($html, $base)
    {
        $host = strtolower((string)parse_url($base, PHP_URL_HOST));
        $out = array('list' => '', 'detail' => '');
        if (!preg_match_all('#href\s*=\s*["\']([^"\'\s<>]+)["\']#i', (string)$html, $m)) {
            return $out;
        }
        $detail = array('/watch/', '/detail/', 'vod/detail', '/vod/play', 'vodplay', '/video/', '/play/');
        $list = array('/category/', '/show/', '/type/', '/label/', '/list/', 'vod/type', 'vod/show', '/tag/', '/genre/');
        foreach ($m[1] as $href) {
            $href = html_entity_decode($href, ENT_QUOTES, 'UTF-8');
            if ($href === '' || stripos($href, 'javascript:') === 0 || $href[0] === '#') {
                continue;
            }
            $abs = Http::resolve($base, $href);
            if (strtolower((string)parse_url($abs, PHP_URL_HOST)) !== $host) {
                continue;
            }
            $low = strtolower($abs);
            if ($out['detail'] === '') {
                foreach ($detail as $d) {
                    if (strpos($low, $d) !== false) {
                        $out['detail'] = $abs;
                        break;
                    }
                }
            }
            if ($out['list'] === '') {
                foreach ($list as $d) {
                    if (strpos($low, $d) !== false) {
                        $out['list'] = $abs;
                        break;
                    }
                }
            }
            if ($out['list'] !== '' && $out['detail'] !== '') {
                break;
            }
        }
        return $out;
    }

    /**
     * GET on the site itself: redirects followed on the same host (3 at most). $headers: extra request lines ("Name:
     * value"); $origin: the server's own IP address (curl tries it first). Returns ['status', 'body', 'error', 'ms',
     * 'headers' (lower-case names)].
     */
    public static function fetch($url, $probe, $deadline, array $headers = array(), $origin = '')
    {
        $t0 = microtime(true);
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $current = $url;
        $r = array('status' => 0, 'body' => '', 'error' => '', 'location' => '', 'headers' => array());
        for ($i = 0; $i < 4; $i++) {
            $r = $origin !== '' ? self::once($current, $probe, $deadline, $headers, $origin) : array('status' => 0);
            if ($r['status'] === 0) {
                // no origin address, or it did not answer: the site's address as the world sees it
                $r = self::once($current, $probe, $deadline, $headers, '');
            }
            if (in_array($r['status'], array(301, 302, 303, 307, 308), true) && $r['location'] !== '') {
                $next = Http::resolve($current, $r['location']);
                if ($next === '') {
                    $r['error'] = 'unsupported redirect (not http or https)';
                    break;
                }
                if (strtolower((string)parse_url($next, PHP_URL_HOST)) !== $host) {
                    $r['error'] = 'redirected to another host';
                    break;
                }
                $current = $next;
                continue;
            }
            break;
        }
        $r['ms'] = (int)round((microtime(true) - $t0) * 1000);
        return $r;
    }

    private static function once($url, $probe, $deadline, array $extra, $origin)
    {
        $timeout = max(2, min(20, (int)ceil($deadline - microtime(true))));
        $headers = array_merge(array('User-Agent: JavCMS-Updater-Health', 'Accept: text/html', 'Cache-Control: no-cache'), $extra);
        if ($probe !== '') {
            $headers[] = 'X-Javcms-Update-Probe: ' . $probe;
        }
        if (function_exists('curl_init') && Util::fnAvailable('curl_exec')) {
            $resolve = array();
            if ($origin !== '' && filter_var($origin, FILTER_VALIDATE_IP)) {
                $p = parse_url($url);
                $port = isset($p['port']) ? (int)$p['port'] : (strtolower((string)$p['scheme']) === 'https' ? 443 : 80);
                $resolve[] = $p['host'] . ':' . $port . ':' . (strpos($origin, ':') !== false ? '[' . $origin . ']' : $origin);
            }
            foreach (array(true, false) as $verify) {
                $ch = curl_init($url);
                $loc = '';
                $got = array();
                $lines = 0;
                curl_setopt_array($ch, array(
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_CONNECTTIMEOUT => $origin !== '' ? min(4, $timeout) : min(10, $timeout), CURLOPT_TIMEOUT => $timeout, CURLOPT_ENCODING => '',
                    CURLOPT_SSL_VERIFYPEER => $verify, CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
                    CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$loc, &$got, &$lines) {
                        if (++$lines > 200) {
                            return 0;
                        }
                        if (stripos($line, 'Location:') === 0) {
                            $loc = trim(substr($line, 9));
                        } elseif (($p = strpos($line, ':')) !== false && count($got) < 100) {
                            $got[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                        }
                        return strlen($line);
                    },
                ));
                if ($resolve) {
                    curl_setopt($ch, CURLOPT_RESOLVE, $resolve);
                }
                if ($verify && is_file(Http::caBundle())) {
                    curl_setopt($ch, CURLOPT_CAINFO, Http::caBundle());
                }
                $body = curl_exec($ch);
                $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $errno = curl_errno($ch);
                $err = $errno ? curl_error($ch) : '';
                if (PHP_VERSION_ID < 80000) {
                    curl_close($ch);
                }
                $tls = in_array($errno, array(35, 51, 53, 58, 60, 77, 80, 82, 83), true);
                if ($tls && $verify) {
                    continue; // the site's own host with a local / panel certificate: once more without verification
                }
                return array('status' => $status, 'body' => is_string($body) ? $body : '', 'error' => $errno ? Util::redact($err) : '', 'location' => $loc, 'headers' => $got);
            }
        }
        if ($origin !== '') {
            return array('status' => 0, 'body' => '', 'error' => 'no curl for the origin address', 'location' => '', 'headers' => array());
        }
        $ctx = stream_context_create(array(
            'http' => array('method' => 'GET', 'header' => implode("\r\n", $headers) . "\r\nConnection: close\r\n", 'follow_location' => 0,
                'ignore_errors' => true, 'timeout' => $timeout),
            'ssl' => array('verify_peer' => false, 'verify_peer_name' => false),
        ));
        $fh = ini_get('allow_url_fopen') ? @fopen($url, 'rb', false, $ctx) : false;
        if (!$fh) {
            return array('status' => 0, 'body' => '', 'error' => 'the site does not answer requests from the server itself', 'location' => '', 'headers' => array());
        }
        $meta = stream_get_meta_data($fh);
        $body = (string)stream_get_contents($fh, 4194304);
        fclose($fh);
        $status = 0;
        $loc = '';
        $got = array();
        foreach (array_slice((array)($meta['wrapper_data'] ?? array()), 0, 200) as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$line, $mm)) {
                $status = (int)$mm[1];
                $got = array();
            } elseif (stripos((string)$line, 'Location:') === 0) {
                $loc = trim(substr((string)$line, 9));
            } elseif (($p = strpos((string)$line, ':')) !== false) {
                $got[strtolower(trim(substr((string)$line, 0, $p)))] = trim(substr((string)$line, $p + 1));
            }
        }
        return array('status' => $status, 'body' => $body, 'error' => '', 'location' => $loc, 'headers' => $got);
    }
}
