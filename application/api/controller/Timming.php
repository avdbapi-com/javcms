<?php
/*
 * This file is part of JavCMS - https://javcms.net
 * Based on MacCMS 10 (Apache-2.0) by magicblack, changed by JavCMS.
 * JavCMS changes: Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors,
 * JavCMS Community License 1.0. The MacCMS parts stay under the Apache License 2.0.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
namespace app\api\controller;
use think\Controller;
require_once dirname(__DIR__, 2) . '/common/util/ScheduledTasks.php';

class Timming extends Base
{
    /**
     * List allowed task methods explicitly. The task names are part of
     * the existing admin configuration and are intentionally preserved.
     */
    private static $allowedTaskMethods = array(
        'collect' => true,
        'make' => true,
        'cj' => true,
        'cache' => true,
        'urlsend' => true,
    );

    public function __construct()
    {
        parent::__construct();
    }

    public function index()
    {
        $param = input('get.', '', 'trim,urldecode');
        if (!is_array($param)) { $param = []; }
        if (Request()->isPost()) { $param = array_replace($param, input('post.', [], '')); }
        if (!$this->isAuthorized($param)) {
            return response('Forbidden', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        // Authorized cron call: one bounded clean-up pass after the task output (DB-SLIM housekeeping).
        register_shutdown_function([\app\common\util\Housekeeping::class, 'onCallback']);
        $name = $param['name'] ?? '';
        if (!is_string($name) || (isset($param['enforce']) && !is_string($param['enforce']))) {
            return response('Invalid task request', 400, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $now = time();
        $timezone = date_default_timezone_get();
        $enforce = isset($param['enforce']) && $param['enforce'] === '1';
        $selected = null;
        try {
            $list = \app\common\util\ScheduledTasks::update(APP_PATH . 'extra/timming.php', RUNTIME_PATH, function ($list) use ($name, $now, $timezone, $enforce, &$selected) {
                foreach ($list as $key => $task) {
                    if (!is_array($task) || ($name !== '' && ($task['name'] ?? '') !== $name)) { continue; }
                    if (!\app\common\util\ScheduledTasks::due($task, $now, $timezone, $enforce)) { continue; }
                    $file = $task['file'] ?? '';
                    if (!is_string($file) || !isset(self::$allowedTaskMethods[$file]) || !is_callable([$this, $file])) { throw new \RuntimeException(); }
                    $selected = ['key' => $key, 'task' => $task];
                    $list[$key]['runtime'] = $now;
                    return $list;
                }
                return $list;
            }, false);
        } catch (\Throwable $error) { return $this->timingServerError(); }
        foreach ($list as $key => $task) {
            if (!is_array($task) || ($name !== '' && ($task['name'] ?? '') !== $name)) { continue; }
            $original = $selected !== null && $selected['key'] === $key ? $selected['task'] : $task;
            $last = !empty($original['runtime']) && is_numeric($original['runtime']) ? date('Y-m-d H:i:s', (int) $original['runtime']) : lang('api/never');
            $status = isset($task['status']) && $task['status'] == '1' ? lang('open') : lang('close');
            $label = htmlspecialchars((string) ($task['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            if ($selected !== null && $selected['key'] === $key) {
                mac_echo(lang('api/task_tip_exec', [$label, $status, $last]));
                $this->reset();
                $file = $task['file'];
                $this->$file(isset($task['param']) && is_string($task['param']) ? $task['param'] : '');
                die;
            }
            mac_echo(lang('api/task_tip_jump', [$label, $status, $last]));
        }
    }

    /**
     * Allow CLI workers and authenticated admin tests; require a configured
     * server token for other HTTP requests. This file does not generate tokens.
     */
    private function isAuthorized($param)
    {
        if (PHP_SAPI === 'cli') { return true; }
        // Existing configured-token cron URLs and headers remain supported.
        $configured = $this->configuredToken();
        $presented = $_SERVER['HTTP_X_MACCMS_TIMMING_TOKEN'] ?? ($_SERVER['REDIRECT_HTTP_X_MACCMS_TIMMING_TOKEN'] ?? ($param['token'] ?? ''));
        if ($this->isStrongToken($configured) && is_string($presented) && hash_equals($configured, trim($presented))) { return true; }
        // An admin test is a named POST, validated using the native session and explicit permission.
        if (!Request()->isPost() || ($param['enforce'] ?? '') !== '1' || !isset($param['name']) || !is_string($param['name']) || $param['name'] === '') { return false; }
        try {
            $login = model('Admin')->checkLogin();
            $info = isset($login['info']) && is_array($login['info']) ? $login['info'] : [];
            if (($login['code'] ?? 0) !== 1 || empty($info['admin_id']) || (string) ($info['admin_status'] ?? '') !== '1') { return false; }
            if ((string) $info['admin_id'] !== '1' && (!isset($info['admin_auth']) || !is_string($info['admin_auth']) || strpos(',' . trim($info['admin_auth'], ',') . ',', ',timming/test,') === false)) { return false; }
            $validate = \think\Loader::validate('Token');
            $valid = $validate->check(input('post.', [], ''));
            header('X-Maccms-Token: ' . Request()->token());
            return $valid;
        } catch (\Throwable $error) { return false; }
    }

    private function configuredToken()
    {
        $token = getenv('MACCMS_TIMMING_TOKEN');
        if ($token === false || $token === '') {
            $token = isset($_SERVER['MACCMS_TIMMING_TOKEN']) ? $_SERVER['MACCMS_TIMMING_TOKEN'] : '';
        }
        if (($token === false || $token === '') && function_exists('config')) {
            $configured = config('timming_token');
            if (!is_string($configured) || $configured === '') {
                $configured = config('api.timming_token');
            }
            if (is_string($configured)) {
                $token = $configured;
            }
        }
        return is_string($token) ? trim($token) : '';
    }

    private function isStrongToken($token)
    {
        return is_string($token) && strlen($token) >= 32 && preg_match('/\s/', $token) !== 1;
    }

    private function timingServerError()
    {
        return response('Timing task configuration error', 500, array('Content-Type' => 'text/plain; charset=utf-8'));
    }

    private function reset()
    {
        foreach($_REQUEST as $k=>$v){
            $_REQUEST[$k]='';
        }
    }

    protected function collect($param)
    {
        @parse_str($param,$output);
        $request = controller('admin/collect');
        $request->api($output);
    }

    protected function make($param)
    {
        @parse_str($param,$output);
        $request = controller('admin/make');
        $request->make($output);
    }

    protected function cj($param)
    {
        @parse_str($param,$output);
        $request = controller('admin/cj');
        $request->col_all($output);
    }

    protected function cache($param)
    {
        // xss-fix-B (function r7 P2-1): admin index/clear() needs a POST with the admin list token, so calling it from
        // here was always refused and the task never cleared anything. The task (an authorized cron call or the admin
        // Test button, both checked in isAuthorized()) calls the clear engine itself, like index/clear does.
        $request = controller('admin/index');
        $ok = $request->_cache_clear();
        model('VodSearch')->clearOldResult(true);
        $msgKey = $ok ? 'admin/ux/shell/cache_cleared' : 'admin/ux/shell/cache_failed';
        $msg = lang($msgKey);
        if ($msg === $msgKey) {
            // The api entry does not load the admin language pack; load it for this one sentence.
            $range = (string)\think\Lang::range();
            $pack = APP_PATH . 'admin/lang/' . (preg_match('/^[a-z]{2}-[a-z]{2}$/D', $range) ? $range : 'en-us') . '.php';
            if (is_file($pack)) {
                \think\Lang::load($pack);
                $msg = lang($msgKey);
            }
        }
        mac_echo(htmlspecialchars((string)$msg, ENT_QUOTES, 'UTF-8'));
    }

    protected function urlsend($param)
    {
        @parse_str($param,$output);
        $request = controller('admin/urlsend');
        $request->push($output);
    }
}
