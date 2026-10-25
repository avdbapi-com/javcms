<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
namespace app\admin\controller;

use app\common\model\AvdbImport as ImportService;
use app\common\util\AvdbSuggestions as SuggestionsService;
use think\Loader;
use think\Request;

/** Dedicated authenticated importer; GET routes never create jobs or schema. */
class Avdbimport extends Base
{
    private $nextToken;

    /**
     * r7 repair (function r6 P3-2): the schedule service stores and throws English sentences. The admin shows them in
     * the admin's language (admin/ux/r7/avdb_sched_<code>); the stored English stays as it is (error_raw, for logs).
     */
    private static $scheduleTexts = [
        'Managed service unavailable. Save again to enable.' => 'service_down',
        'Registration failed.' => 'register_refused',
        'Owner permission is no longer available.' => 'owner_lost',
        'Import requires administrator attention.' => 'needs_attention',
        'Waiting for the manual import to finish.' => 'waiting_manual',
        'Automatic import stopped. Review the import job before resuming.' => 'stopped_review',
        'Public HTTPS callback is required.' => 'https_required',
        'Public callback URL is invalid.' => 'callback_invalid',
        'Set the public HTTPS site address before enabling automatic imports.' => 'set_site_https',
        'The installation path is invalid.' => 'install_path',
        'Automatic imports require all matching videos.' => 'need_all',
        'Interval must be 1 to 10080 minutes.' => 'interval',
        'Schedule changed. Refresh before resuming.' => 'changed',
        'Review the stopped import job before resuming.' => 'review_job',
        'The previous import step is still finishing. Try resuming again shortly.' => 'step_busy',
        'Provider rate limit is still active. Try resuming again shortly.' => 'rate_limit',
        'The import is not owned by this schedule.' => 'not_owned',
        'Schedule is unavailable.' => 'unavailable',
        'Schedule permission denied.' => 'denied',
    ];

    private function scheduleText($text)
    {
        $text = (string)$text;
        if (isset(self::$scheduleTexts[$text])) {
            $key = 'admin/ux/r7/avdb_sched_' . self::$scheduleTexts[$text];
            $out = lang($key);
            if ($out !== $key) {
                return $out;
            }
        }
        return $text;
    }
    public function index()
    {
        // N3 / O2: page title and header from the menu strings (fallback: this page's own strings).
        $title = lang('admin/ux/menu/avdb_import');
        $title = $title === 'admin/ux/menu/avdb_import' ? lang('admin/ux/tools/avdb_title') : $title;
        $help = lang('admin/ux/help/avdb_import');
        $help = $help === 'admin/ux/help/avdb_import' ? lang('admin/ux/tools/avdb_subtitle') : $help;
        $this->assign('title', $title);
        $this->assign('ax_head', ['title' => $title, 'help' => $help]);
        // r5 repair (beginner r5 P2-5): the "First import? Start here" guide opens until the first import exists.
        $hasHistory = false;
        try {
            $hasHistory = (bool)\think\Db::name('avdb_import_job')->limit(1)->value('job_id');
        } catch (\Throwable $e) {
            $hasHistory = false;
        }
        $this->assign('avdb_has_history', $hasHistory);
        // Every admin/ux/tools/avdb_* string for avdb_import.js (window.AX_AVDB_LANG, keys without the prefix).
        $axl = [];
        foreach ((array)\think\Lang::get() as $key => $text) {
            if (is_string($key) && is_string($text) && strpos($key, 'admin/ux/tools/avdb_') === 0) {
                $axl[substr($key, strlen('admin/ux/tools/avdb_'))] = $text;
            }
        }
        $this->assign('avdb_lang_js', json_encode((object)$axl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
        $this->assign('avdb_ui', json_encode([
            'smart' => lang('admin/avdb/smart'),
            'smart_help' => lang('admin/avdb/smart_help'),
            'skip' => lang('admin/avdb/skip'),
            'update' => lang('admin/avdb/update'),
            'requested_languages' => lang('admin/avdb/requested_languages'),
            'available_languages' => lang('admin/avdb/available_languages'),
            'languages_unknown' => lang('admin/avdb/languages_unknown'),
            'missing_language_hint' => lang('admin/avdb/missing_language_hint'),
            'language_phase' => lang('admin/avdb/language_phase'),
            'review_filters' => lang('admin/avdb/review_filters'),
            'all_matching' => lang('admin/avdb/all_matching'),
            'unlimited' => lang('admin/avdb/unlimited'),
            'native_preview' => lang('admin/avdb/native_preview'),
            'tag' => lang('admin/avdb/tag'),
            'tag_hint' => lang('admin/avdb/tag_hint'),
            'default_player' => lang('admin/avdb/default_player'),
            'player_hint' => lang('admin/avdb/player_hint'),
            'apply_preview' => lang('admin/avdb/apply_preview'),
            'preview_required' => lang('admin/avdb/preview_required'),
            'all_scope' => lang('admin/avdb/all_scope'),
            'limit_hint' => lang('admin/avdb/limit_hint'),
            'filters_used' => lang('admin/avdb/filters_used'),
            'languages_default' => lang('admin/avdb/languages_default'),
            'schedule_title' => lang('admin/avdb/schedule_title'),
            'schedule_help' => lang('admin/avdb/schedule_help'),
            'schedule_enable' => lang('admin/avdb/schedule_enable'),
            'schedule_interval' => lang('admin/avdb/schedule_interval'),
            'schedule_15m' => lang('admin/avdb/schedule_15m'),
            'schedule_1h' => lang('admin/avdb/schedule_1h'),
            'schedule_6h' => lang('admin/avdb/schedule_6h'),
            'schedule_12h' => lang('admin/avdb/schedule_12h'),
            'schedule_daily' => lang('admin/avdb/schedule_daily'),
            'schedule_save' => lang('admin/avdb/schedule_save'),
            'schedule_refresh' => lang('admin/avdb/schedule_refresh'),
            'schedule_pause' => lang('admin/avdb/schedule_pause'),
            'schedule_resume' => lang('admin/avdb/schedule_resume'),
            'schedule_review' => lang('admin/ux/tools/avdb_schedule_note'),
            'schedule_off' => lang('admin/avdb/schedule_off'),
            'schedule_idle' => lang('admin/avdb/schedule_idle'),
            'schedule_running' => lang('admin/avdb/schedule_running'),
            'schedule_paused' => lang('admin/avdb/schedule_paused'),
            'schedule_error' => lang('admin/avdb/schedule_error'),
            'schedule_next' => lang('admin/avdb/schedule_next'),
            'schedule_job' => lang('admin/avdb/schedule_job'),
            'schedule_saved' => lang('admin/avdb/schedule_saved'),
            'schedule_none' => lang('admin/avdb/schedule_none'),
            'schedule_unavailable' => lang('admin/avdb/schedule_unavailable'),
            'schedule_invalid' => lang('admin/avdb/schedule_invalid'),
            'schedule_limit' => lang('admin/avdb/schedule_limit'),
            'schedule_unlimited' => lang('admin/avdb/schedule_unlimited'),
            'schedule_languages' => lang('admin/avdb/schedule_languages'),
            'schedule_published' => lang('admin/avdb/schedule_published'),
            'schedule_drafts' => lang('admin/avdb/schedule_drafts'),
            'schedule_minutes' => lang('admin/avdb/schedule_minutes'),

        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));

        $this->assign('__token__', Request::instance()->token('__token__'));
        return $this->fetch('admin@collect/avdb');
    }

    public function suggestions()
    {
        if (!Request::instance()->isGet()) { return $this->reply(0, 'This action requires GET.', []); }
        try {
            $this->adminId();
            $params = input('get.');
            $options = ['field'=>$params['field'] ?? '', 'q'=>$params['q'] ?? '', 'pg'=>$params['pg'] ?? 1, 'pagesize'=>$params['pagesize'] ?? 20];
            // Authentication is complete; slow metadata requests must not block status polling.
            if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
            $data = (new SuggestionsService())->suggest($options);
            return $this->reply(1, 'OK', $data);
        } catch (\Throwable $e) { return $this->failure($e, false); }
    }

    public function capabilities() { return $this->read('capabilities'); }
    /** C7 estimate for a scope (GET provider, scope, limit, type, images, skip_*, langs, preset, categories_off). */
    public function estimate() { return $this->read('estimate'); }
    /** C7 providers list with the pacer state, local videos, takedown summary (HO-E-3). */
    public function providers() { return $this->read('providers'); }
    /** Videos the provider no longer lists (hidden by the presence check). */
    public function takedowns() { return $this->read('takedowns'); }
    /** POST gb = number | auto | none: the "My database limit" setting (brief 2.2, StorageGuard::setLimit). */
    public function dblimit() { return $this->write('dblimit'); }
    public function takedownDelete() { return $this->write('takedownDelete'); }
    public function undo() { return $this->write('undo'); }
    public function removeAll() { return $this->write('removeAll'); }
    public function allowImageHost() { return $this->write('allowImageHost'); }
    public function preview() { return $this->read('preview'); }
    public function status() { return $this->read('status'); }
    public function jobs() { return $this->read('jobs'); }
    public function items() { return $this->read('items'); }
    public function start() { return $this->write('start'); }
    public function step() { return $this->write('step'); }
    public function pause() { return $this->write('pause'); }
    public function cancel() { return $this->write('cancel'); }
    public function resume() { return $this->write('resume'); }
    public function retry() { return $this->write('retry'); }
    public function rollback() { return $this->write('rollback'); }


    public function scheduleStatus() { return $this->scheduleAction('status',false); }
    public function scheduleSave() { return $this->scheduleAction('save',true); }
    public function scheduleOff() { return $this->scheduleAction('off',true); }
    public function schedulePause() { return $this->scheduleAction('pause',true); }
    public function scheduleResume() { return $this->scheduleAction('resume',true); }
    private function scheduleAction($action,$write)
    {
        if ($write ? !Request::instance()->isPost() : !Request::instance()->isGet()) { return $this->reply(0,$write?'This action requires POST.':'This action requires GET.',[]); }
        $this->nextToken=null;
        try {
            $params=input($write?'post.':'get.');
            $this->provider(is_array($params)?$params:[]);
            if (isset($params['options']) && is_array($params['options'])) { foreach ((array)($params['options']['plans'] ?? []) as $plan) { if (is_array($plan)) { $this->provider($plan,true); } } }
            if ($write) { $validator=Loader::validate('Token'); if (!$validator->check($params)) { return $this->reply(0,$validator->getError(),[],true); } $this->nextToken=Request::instance()->token('__token__'); }
            $owner=$this->adminId();
            if (session_status()===PHP_SESSION_ACTIVE) { session_write_close(); }
            $service=new \app\common\model\AvdbSchedule();
            if ($action==='save') {
                $options=$params['options']??null;
                if (!is_array($options)) { throw new \InvalidArgumentException('Invalid import options.'); }
                $data=$service->save($owner,$options,$params['interval_minutes']??1440,$this->scheduleCallback());
            } else { $data=$service->$action($owner); }
            if (is_array($data) && isset($data['error']) && (string)$data['error'] !== '') {
                $data['error_raw'] = (string)$data['error'];
                $data['error'] = $this->scheduleText($data['error']);
            }
            return $this->reply(1,'OK',$data,$write);
        } catch (\Throwable $e) { return $this->failure($e,$write); }
    }
    private function scheduleCallback()
    {
        $siteConfig=$GLOBALS['config']['site'] ?? [];
        // ThinkPHP 5.0 supports only one nested dot in config() lookups.
        $site=trim((string)($siteConfig['site_url'] ?? ''));
        if (strpos($site,'://')===false) { $site='https://'.$site; }
        $p=parse_url($site);
        if (!$p || !in_array(strtolower($p['scheme']??''),['http','https'],true) || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) { throw new \RuntimeException('Set the public HTTPS site address before enabling automatic imports.'); }
        $path=rtrim($p['path']??'','/');
        if ($path==='') { $path=rtrim((string)($siteConfig['install_dir'] ?? '/'),'/'); }
        if ($path!=='' && !preg_match('~^(?:/[A-Za-z0-9_-]+)+$~D',$path)) { throw new \RuntimeException('The installation path is invalid.'); }
        return 'https://'.$p['host'].(isset($p['port'])?':'.$p['port']:'').$path.'/api.php/avdbschedule/index';
    }

    private function read($action)
    {
        if (!Request::instance()->isGet()) { return $this->reply(0, 'This action requires GET.', []); }
        try {
            $owner = $this->adminId();
            // GET does not change session state; release the lock before provider work.
            if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
            $service = new ImportService();
            $params = input('get.');
            // Every action: a provider parameter must be a registry key (never a URL or class name from input).
            $provider = $this->provider($params);
            if ($action === 'estimate') {
                unset($params['s'], $params['__token__']);
                $data = ['providers' => $service->providersInfo(), 'estimate' => $service->estimateFor(['provider' => $provider] + $params, true)];
                return $this->reply(1, 'OK', $data);
            }
            if ($action === 'providers') {
                return $this->reply(1, 'OK', ['providers' => $service->providersInfo()]);
            }
            if ($action === 'takedowns') {
                $data = $service->takedowns($this->provider($params, true), (int)($params['pg'] ?? 1), (int)($params['pagesize'] ?? 20));
                foreach ($data['items'] as &$item) { $item['vod_name'] = (string)($item['vod_name'] ?? ''); $item['hidden_at'] = (int)($item['missed_at'] ?? 0); } unset($item);
                return $this->reply(1, 'OK', $data);
            }
            if ($action === 'preview' && $provider !== 'avdb') { throw new \InvalidArgumentException('This provider has no preview; choose a scope instead.'); }
            if ($action === 'preview') {
                $filters = $params['filters'] ?? $params;
                if (!is_array($filters)) { throw new \InvalidArgumentException('Invalid search filters.'); }
                // ThinkPHP rewrite route data is not a provider filter.
                unset($filters['s'], $filters['__token__']);
                $data = $service->preview($filters);
            } elseif ($action === 'status') {
                $data = $service->status($owner, $this->jobId($params));
            } elseif ($action === 'jobs') {
                $data = $service->jobs($owner);
            } elseif ($action === 'items') {
                $options = ['state'=>$params['state'] ?? '', 'pg'=>$params['pg'] ?? 1, 'pagesize'=>$params['pagesize'] ?? 50];
                $data = $service->items($owner, $this->jobId($params), $options);
            } else {
                $data = isset($params['provider']) ? $service->providerCapabilities($provider) : $service->capabilities();
            }
            return $this->reply(1, 'OK', $data);
        } catch (\Throwable $e) { return $this->failure($e, false); }
    }

    private function write($action)
    {
        if (!Request::instance()->isPost()) { return $this->reply(0, 'This action requires POST.', []); }
        $this->nextToken = null;
        try {
            $params = input('post.');
            $validator = Loader::validate('Token');
            if (!$validator->check($params)) { return $this->reply(0, $validator->getError(), [], true); }
            $owner = $this->adminId();
            // Persist the replacement token before slow network/database work.
            // Plain write-close keeps ThinkPHP from reopening the session lock.
            $this->nextToken = Request::instance()->token('__token__');
            if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
            $service = new ImportService();
            $provider = $this->provider($params);
            if ($action === 'start') {
                $options = $params['options'] ?? $params;
                if (!is_array($options)) { throw new \InvalidArgumentException('Invalid import options.'); }
                unset($options['__token__']);
                $this->provider($options);
                // Scope requests (E's scope panel) get the server-side checks of the page; the 1.1.0 options are unchanged.
                $data = isset($options['scope']) ? $service->startScope($owner, $options, false) : $service->start($owner, $options);
            } elseif ($action === 'dblimit') {
                $value = \app\common\util\StorageGuard::setLimit($params['gb'] ?? '');
                $data = ['db_limit_gb' => $value, 'db_limit_mb' => \app\common\util\StorageGuard::limitMb(), 'db_limit_asked' => true];
            } elseif ($action === 'takedownDelete') {
                $data = $service->takedownDelete($owner, $this->provider($params, true), (string)($params['scope'] ?? ''));
            } elseif ($action === 'removeAll') {
                $data = $service->removeAll($owner, $this->provider($params, true), (string)($params['confirm'] ?? ''));
            } elseif ($action === 'allowImageHost') {
                $data = $service->allowImageHost($owner, $this->jobId($params), (string)($params['host'] ?? ''));
            } else {
                $data = $service->$action($owner, $this->jobId($params));
            }
            return $this->reply(1, 'OK', $data, true);
        } catch (\Throwable $e) { return $this->failure($e, true); }
    }

    /**
     * The provider of a request: a key of the static registry (ImportProviders), never anything else. Absent ->
     * 'avdb' (1.1.0 requests), or refused when $required.
     */
    private function provider(array $params, $required = false)
    {
        if (!array_key_exists('provider', $params) || $params['provider'] === '' || $params['provider'] === null) {
            if ($required) { throw new \InvalidArgumentException(\app\common\util\ImportProviders::text('provider_unknown', 'Unknown import provider.')); }
            return 'avdb';
        }
        if (!\app\common\util\ImportProviders::isKey($params['provider'])) { throw new \InvalidArgumentException(\app\common\util\ImportProviders::text('provider_unknown', 'Unknown import provider.')); }
        return $params['provider'];
    }

    private function adminId()
    {
        $id = (int)($this->_admin['admin_id'] ?? 0);
        if ($id < 1) { throw new \RuntimeException('Administrator login is required.'); }
        return $id;
    }

    private function jobId(array $params)
    {
        $id = $params['job_id'] ?? $params['id'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $id)) { throw new \InvalidArgumentException('Invalid import job ID.'); }
        return $id;
    }

    private function failure(\Throwable $error, $fresh)
    {
        // Expected validation/network failures are actionable; database/internal details stay private.
        $expected = $error instanceof \InvalidArgumentException || get_class($error) === 'RuntimeException' || $error instanceof \app\common\util\AvdbClientException || $error instanceof \app\common\util\ProviderHttpException || $error instanceof \app\common\util\ProviderWaitException;
        $data = [];
        if (method_exists($error, 'getRetryAfter')) { $data['retry_after'] = $error->getRetryAfter(); }
        if ($expected && in_array($error->getMessage(), ['vod/cleanup_busy','vod/cleanup_import_sealed'], true)) { return $this->reply(0, lang($error->getMessage()), $data, $fresh); }
        return $this->reply(0, $expected ? $this->scheduleText($error->getMessage()) : 'AVDB import could not complete this request. Check the server error log.', $data, $fresh);
    }

    private function reply($code, $message, $data, $fresh = false)
    {
        if (!is_array($data)) { $data = ['result'=>$data]; }
        if ($fresh) { $data['__token__'] = $this->nextToken !== null ? $this->nextToken : Request::instance()->token('__token__'); }
        // xss-fix-A-2 (N-3): the angle brackets and ampersand travel as JSON unicode escapes, so a request value echoed in a message is never markup.
        return json(['code'=>$code, 'msg'=>$message, 'data'=>$data], 200, [], ['json_encode_param' => JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP]);
    }
}
