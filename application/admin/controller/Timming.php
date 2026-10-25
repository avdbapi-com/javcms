<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Based on MacCMS 10 (Apache-2.0) by magicblack, changed by JavCMS.
 * JavCMS changes: Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors,
 * JavCMS Community License 1.0. The MacCMS parts stay under the Apache License 2.0.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
namespace app\admin\controller;
require_once dirname(__DIR__, 2) . '/common/util/ScheduledTasks.php';
use app\common\util\ScheduledTasks;

class Timming extends Base
{
    public function index()
    {
        $list = config('timming');
        $this->assign('list', is_array($list) ? $list : []);
        $this->assign('title', lang('admin/timming/title'));
        $this->assign('apiTestUrl', rtrim($GLOBALS['config']['site']['install_dir'], '/') . '/api.php/timming/index.html');
        $this->assign('simpleAutomationUrl', url('avdbimport/index'));
        return $this->fetch('admin@timming/index');
    }

    private function validateMutation()
    {
        if (!Request()->isPost()) { return $this->ajaxErrorWithFreshToken(lang('illegal_request')); }
        $post = input('post.', [], '');
        $validate = \think\Loader::validate('Token');
        return $validate->check($post) ? null : $this->ajaxErrorWithFreshToken($validate->getError());
    }

    public function info()
    {
        if (Request()->isPost()) {
            $denied = $this->validateMutation();
            if ($denied !== null) { return $denied; }
            $post = input('post.', [], '');
            try {
                ScheduledTasks::update(APP_PATH . 'extra/timming.php', RUNTIME_PATH, function ($list) use ($post) {
                    $name = ScheduledTasks::name($post['name'] ?? '');
                    $existing = isset($list[$name]) && is_array($list[$name]) ? $list[$name] : [];
                    $list[$name] = ScheduledTasks::edit($existing, $post);
                    return $list;
                });
            } catch (\InvalidArgumentException $error) {
                return $this->ajaxErrorWithFreshToken(lang('admin/tasksetup/invalid'));
            } catch (\Throwable $error) {
                return $this->ajaxErrorWithFreshToken(lang('write_err_config'));
            }
            return $this->ajaxSuccessWithFreshToken(lang('save_ok'));
        }
        if (!Request()->isGet()) { return $this->ajaxErrorWithFreshToken(lang('illegal_request')); }
        $list = config('timming');
        $query = input('get.', [], '');
        $id = $query['id'] ?? '';
        if (!is_string($id) || ($id !== '' && (!is_array($list) || !isset($list[$id]) || !is_array($list[$id])))) {
            return $this->error(lang('param_err'));
        }
        $info = $id === '' ? ['name'=>'', 'des'=>'', 'file'=>'collect', 'param'=>'', 'status'=>'0', 'weeks'=>'', 'hours'=>'', 'runtime'=>0] : $list[$id];
        $this->assign('info', $info);
        $this->assign('selectedWeeks', ScheduledTasks::selected($info['weeks'] ?? '', 6));
        $this->assign('selectedHours', ScheduledTasks::selected($info['hours'] ?? '', 23, true));
        $this->assign('title', lang('admin/timming/title'));
        return $this->fetch('admin@timming/info');
    }

    public function del()
    {
        $denied = $this->validateMutation();
        if ($denied !== null) { return $denied; }
        $post = input('post.', [], '');
        try {
            $name = ScheduledTasks::name($post['ids'] ?? '');
            ScheduledTasks::update(APP_PATH . 'extra/timming.php', RUNTIME_PATH, function ($list) use ($name) {
                if (!array_key_exists($name, $list)) { throw new \InvalidArgumentException(); }
                unset($list[$name]);
                return $list;
            });
        } catch (\InvalidArgumentException $error) { return $this->ajaxErrorWithFreshToken(lang('param_err')); }
        catch (\Throwable $error) { return $this->ajaxErrorWithFreshToken(lang('del_err')); }
        return $this->ajaxSuccessWithFreshToken(lang('del_ok'));
    }

    public function field()
    {
        $denied = $this->validateMutation();
        if ($denied !== null) { return $denied; }
        $post = input('post.', [], '');
        try {
            if (($post['col'] ?? '') !== 'status' || !isset($post['val']) || !is_string($post['val']) || !in_array($post['val'], ['0', '1'], true)) { throw new \InvalidArgumentException(); }
            $ids = $post['ids'] ?? [];
            if (is_string($ids)) { $ids = explode(',', $ids); }
            if (!is_array($ids) || !$ids || count($ids) > 500) { throw new \InvalidArgumentException(); }
            $names = [];
            foreach ($ids as $id) { $names[] = ScheduledTasks::name($id); }
            ScheduledTasks::update(APP_PATH . 'extra/timming.php', RUNTIME_PATH, function ($list) use ($names, $post) {
                foreach ($names as $name) {
                    if (!isset($list[$name]) || !is_array($list[$name])) { throw new \InvalidArgumentException(); }
                    $list[$name]['status'] = $post['val'];
                }
                return $list;
            });
        } catch (\InvalidArgumentException $error) { return $this->ajaxErrorWithFreshToken(lang('param_err')); }
        catch (\Throwable $error) { return $this->ajaxErrorWithFreshToken(lang('save_err')); }
        return $this->ajaxSuccessWithFreshToken(lang('save_ok'));
    }
}
