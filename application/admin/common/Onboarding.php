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
 * Per-admin "Getting started" state (brief ADMIN-IA-BRIEF 4.7).
 *
 * File: application/data/config/onboarding.json
 *   { "<admin_id>": {"hidden": false, "done": {"6": 1}, "flags": {"theme_chosen": 1, "pwd_changed": 1}} }
 *
 * Only this class writes the file. A missing or broken file reads as "all empty" and never raises an error.
 *
 *   Onboarding::get($adminId)            -> ['hidden'=>bool, 'done'=>[step=>1], 'flags'=>[name=>1]]
 *   Onboarding::set($adminId, $patch)    -> merges $patch ('hidden' bool, 'done' [step=>1|0], 'flags' [name=>1|0]); returns bool
 *   Onboarding::flag($adminId, $name)    -> sets flags[$name] = 1; returns bool
 */
class Onboarding
{
    const FILE = 'data/config/onboarding.json';

    protected static function path()
    {
        return APP_PATH . self::FILE;
    }

    protected static function blank()
    {
        return ['hidden' => false, 'done' => [], 'flags' => []];
    }

    protected static function readAll()
    {
        $file = self::path();
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    protected static function clean($row)
    {
        $out = self::blank();
        if (!is_array($row)) {
            return $out;
        }
        $out['hidden'] = !empty($row['hidden']);
        foreach (['done', 'flags'] as $part) {
            if (!empty($row[$part]) && is_array($row[$part])) {
                foreach ($row[$part] as $k => $v) {
                    $k = (string)$k;
                    if ($k !== '' && preg_match('/^[a-z0-9_]{1,40}$/', $k) && !empty($v)) {
                        $out[$part][$k] = 1;
                    }
                }
            }
        }
        return $out;
    }

    public static function get($adminId)
    {
        $id = (string)intval($adminId);
        $all = self::readAll();
        return self::clean(isset($all[$id]) ? $all[$id] : []);
    }

    public static function set($adminId, $patch)
    {
        $id = intval($adminId);
        if ($id < 1 || !is_array($patch)) {
            return false;
        }
        $all = self::readAll();
        $row = self::clean(isset($all[(string)$id]) ? $all[(string)$id] : []);
        if (array_key_exists('hidden', $patch)) {
            $row['hidden'] = !empty($patch['hidden']);
        }
        foreach (['done', 'flags'] as $part) {
            if (!empty($patch[$part]) && is_array($patch[$part])) {
                foreach ($patch[$part] as $k => $v) {
                    $k = (string)$k;
                    if ($k === '' || !preg_match('/^[a-z0-9_]{1,40}$/', $k)) {
                        continue;
                    }
                    if (empty($v)) {
                        unset($row[$part][$k]);
                    } else {
                        $row[$part][$k] = 1;
                    }
                }
            }
        }
        // JSON objects, never lists, so step "6" stays a key.
        $all[(string)$id] = [
            'hidden' => $row['hidden'],
            'done' => (object)$row['done'],
            'flags' => (object)$row['flags'],
        ];
        $file = self::path();
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return false;
        }
        $json = json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            return false;
        }
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return false;
        }
        if (!@rename($tmp, $file)) {
            // Windows cannot rename over an existing file on some PHP builds.
            $ok = @file_put_contents($file, $json, LOCK_EX) !== false;
            @unlink($tmp);
            return $ok;
        }
        return true;
    }

    public static function flag($adminId, $name)
    {
        return self::set($adminId, ['flags' => [(string)$name => 1]]);
    }
}
