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
use think\Db;
use think\addons\AddonException;
use think\addons\Service;
use think\Cache;
use think\Config;
use think\Exception;
use app\common\util\Dir;

class Addon extends Base
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * F40: the page lists the add-ons installed in addons/ (server side, no JSONP) and says that the
     * add-on store is offline when there are none. Nothing is fetched from the old MacCMS store.
     */
    public function index()
    {
        $list = [];
        foreach ((array)get_addon_list() as $row) {
            if (!is_array($row) || empty($row['name'])) {
                continue;
            }
            $list[] = [
                'name' => (string)$row['name'],
                'title' => (string)($row['title'] ?? $row['name']),
                'intro' => (string)($row['intro'] ?? ''),
                'version' => (string)($row['version'] ?? ''),
                'state' => !empty($row['state']) ? 1 : 0,
                'config' => get_addon_config($row['name']) ? 1 : 0,
            ];
        }
        $this->assign('list', $list);
        $this->assign('title',lang('admin/addon/title'));
        return $this->fetch('admin@addon/index');
    }

    /**
     * close-security (security r4 P3-4): Add-on settings. The MacCMS add-on store is retired in JAVCMS (no add-ons ship,
     * nothing can be downloaded), so this old write action answers "not included" and changes nothing.
     */
    public function config()
    {
        return $this->error(lang('admin/module_removed'));
    }

    public function info()
    {

    }

    public function downloaded()
    {
        $offset = (int)$this->request->get("offset");
        $limit = (int)$this->request->get("limit");
        $filter = $this->request->get("filter");
        $search = $this->request->get("search");
        $search = htmlspecialchars(strip_tags($search));
        // The old online catalogue (a MacCMS server) is gone: only add-ons installed in addons/ are listed.
        $onlineaddons = [];
        $filter = (array)json_decode($filter, true);
        $addons = get_addon_list();
        $list = [];
        foreach ($addons as $k => $v) {
            if ($search && stripos($v['name'], $search) === FALSE && stripos($v['intro'], $search) === FALSE)
                continue;

            if (isset($onlineaddons[$v['name']])) {
                $v = array_merge($onlineaddons[$v['name']], $v);
            } else {
                if(!isset($v['category_id'])) {
                    $v['category_id'] = 0;
                }
                if(!isset($v['flag'])) {
                    $v['flag'] = '';
                }
                if(!isset($v['banner'])) {
                    $v['banner'] = '';
                }
                if(!isset($v['image'])) {
                    $v['image'] = '';
                }
                if(!isset($v['donateimage'])) {
                    $v['donateimage'] = '';
                }
                if(!isset($v['demourl'])) {
                    $v['demourl'] = '';
                }
                if(!isset($v['price'])) {
                    $v['price'] = '0.00';
                }
            }
            $v['url'] = addon_url($v['name']);
            $v['createtime'] = filemtime(ADDON_PATH . $v['name']);
            $v['install'] = '1';
            if ($filter && isset($filter['category_id']) && is_numeric($filter['category_id']) && $filter['category_id'] != $v['category_id']) {
                continue;
            }
            $list[] = $v;
        }
        $total = count($list);
        if ($limit) {
            $list = array_slice($list, $offset, $limit);
        }
        $result = array("total" => $total, "rows" => $list);

        $callback = $this->request->get('callback') ? "jsonp" : "json";
        return $callback($result);
    }

    /**
     * close-security (security r4 P3-4): Install an add-on. The MacCMS add-on store is retired in JAVCMS (no add-ons ship,
     * nothing can be downloaded), so this old write action answers "not included" and changes nothing.
     */
    public function install()
    {
        return $this->error(lang('admin/module_removed'));
    }

    /**
     * close-security (security r4 P3-4): Uninstall an add-on. The MacCMS add-on store is retired in JAVCMS (no add-ons ship,
     * nothing can be downloaded), so this old write action answers "not included" and changes nothing.
     */
    public function uninstall()
    {
        return $this->error(lang('admin/module_removed'));
    }

    /**
     * close-security (security r4 P3-4): Turn an add-on on or off. The MacCMS add-on store is retired in JAVCMS (no add-ons ship,
     * nothing can be downloaded), so this old write action answers "not included" and changes nothing.
     */
    public function state()
    {
        return $this->error(lang('admin/module_removed'));
    }

    /* *
 * Local upload */
    public function local()
    {
        $param = input();
        $validate = \think\Loader::validate('Token');
        if(!$validate->check($param)){
            return $this->error($validate->getError());
        }
        echo 'closed';exit;
        $file = $this->request->file('file');
        $addonTmpDir = RUNTIME_PATH . 'addons' . DS;
        if (!is_dir($addonTmpDir)) {
            @mkdir($addonTmpDir, 0755, true);
        }
        $info = $file->rule('uniqid')->validate(['size' => 10240000, 'ext' => 'zip'])->move($addonTmpDir);
        if ($info) {
            $tmpName = substr($info->getFilename(), 0, stripos($info->getFilename(), '.'));
            $tmpAddonDir = ADDON_PATH . $tmpName . DS;
            $tmpFile = $addonTmpDir . $info->getSaveName();
            try {
                Service::unzip($tmpName);
                @unlink($tmpFile);
                $infoFile = $tmpAddonDir . 'info.ini';
                if (!is_file($infoFile)) {
                    throw new Exception(lang('admin/addon/lack_config_err'));
                }

                $config = Config::parse($infoFile, '', $tmpName);
                $name = isset($config['name']) ? $config['name'] : '';
                if (!$name) {
                    throw new Exception(lang('admin/addon/name_empty_err'));
                }

                $newAddonDir = ADDON_PATH . $name . DS;
                if (is_dir($newAddonDir)) {
                    throw new Exception(lang('admin/addon/haved_err'));
                }

                // Rename the add-on folder
                rename($tmpAddonDir, $newAddonDir);
                try {
                    // Disable the add-on if it is enabled
                    $info = get_addon_info($name);
                    if ($info['state']) {
                        $info['state'] = 0;
                        set_addon_info($name, $info);
                    }

                    // Call the add-on's install method
                    $class = get_addon_class($name);
                    if (class_exists($class)) {
                        $addon = new $class();
                        $addon->install();
                    }

                    // Import SQL
                    Service::importsql($name);

                    $info['config'] = get_addon_config($name) ? 1 : 0;
                    return $this->success(lang('install_ok'));
                } catch (Exception $e) {
                    if (Dir::delDir($newAddonDir) === false) {

                    }
                    throw new Exception($e->getMessage());
                }
            } catch (Exception $e) {
                @unlink($tmpFile);
                if (Dir::delDir($tmpAddonDir) === false) {

                }
                return $this->error($e->getMessage());
            }
        } else {
            // Return the upload error
            return $this->error($file->getError());
        }
    }

    public function add()
    {
        return $this->fetch('admin@addon/add');
    }
    /**
     * close-security (security r4 P3-4): Upgrade an add-on. The MacCMS add-on store is retired in JAVCMS (no add-ons ship,
     * nothing can be downloaded), so this old write action answers "not included" and changes nothing.
     */
    public function upgrade()
    {
        return $this->error(lang('admin/module_removed'));
    }

}
