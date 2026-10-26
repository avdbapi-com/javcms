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
namespace app\admin\common;

/**
 * r5 (admin-leftovers): gate for the old multi-step admin jobs that run as a chain of GET jump links
 * (bulk change of videos / articles / websites, Images > sync pictures).
 *
 * Step 1 must be a POST with the session admin token (the page's button). It starts a run: a random key kept in the
 * admin session together with the job settings of step 1. The later steps are the GET jump links of the old engine; they
 * carry only run=<key> and the page counters, never the admin token, and they use the settings stored in the session, so a
 * changed link cannot change what the job writes. A GET without a valid key changes nothing.
 * A run ends when the job finishes, or after $runKeyIdle seconds without a step.
 */
trait RunKey
{
    /** Seconds a run may stay idle between two steps. */
    protected static $runKeyIdle = 900;

    /** POST + the session admin token (__token__ in the body or the X-CSRF-Token header). */
    protected function runKeyTokenOk()
    {
        if (!request()->isPost()) {
            return false;
        }
        $sent = input('post.__token__', '');
        $sent = is_string($sent) ? trim($sent) : '';
        if ($sent === '') {
            $sent = trim((string)request()->header('X-CSRF-Token'));
        }
        $expected = function_exists('mac_admin_csrf_token') ? (string)mac_admin_csrf_token() : '';
        return $sent !== '' && $expected !== '' && hash_equals($expected, $sent);
    }

    /**
     * Step 1: needs POST + the admin token. Stores $bind (the job settings) and returns the new run key, or false.
     */
    protected function runKeyStart($job, array $bind)
    {
        if (!$this->runKeyTokenOk()) {
            return false;
        }
        unset($bind['__token__'], $bind['run'], $bind['page'], $bind['total'], $bind['page_count']);
        $key = bin2hex(random_bytes(12));
        session('admin_run_' . $job, ['k' => $key, 't' => time(), 'p' => $bind]);
        return $key;
    }

    /**
     * Steps 2..n (GET): returns the settings stored at step 1 plus the page counters of the link, or false when the
     * key is missing, wrong or expired.
     */
    protected function runKeyResume($job, array $param)
    {
        $run = isset($param['run']) && is_string($param['run']) ? $param['run'] : '';
        $saved = session('admin_run_' . $job);
        if ($run === '' || !is_array($saved) || !isset($saved['k'], $saved['t'], $saved['p']) || !is_array($saved['p'])
            || !hash_equals((string)$saved['k'], $run) || (time() - (int)$saved['t']) > static::$runKeyIdle) {
            return false;
        }
        $saved['t'] = time();
        session('admin_run_' . $job, $saved);
        $out = $saved['p'];
        $out['run'] = $run;
        foreach (['page', 'total', 'page_count'] as $k) {
            if (isset($param[$k]) && is_scalar($param[$k]) && (string)$param[$k] !== '') {
                $out[$k] = max(0, (int)$param[$k]);
            }
        }
        return $out;
    }

    /** The job is finished: the key stops working. */
    protected function runKeyEnd($job)
    {
        session('admin_run_' . $job, null);
    }

    /** The jump link to the next step: only the run key and the page counters. */
    protected function runKeyNextUrl($url, array $param)
    {
        $q = ['run' => isset($param['run']) ? (string)$param['run'] : ''];
        foreach (['page', 'total', 'page_count'] as $k) {
            if (isset($param[$k])) {
                $q[$k] = (int)$param[$k];
            }
        }
        return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($q);
    }
}
