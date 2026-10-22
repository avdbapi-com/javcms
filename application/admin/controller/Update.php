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
namespace app\admin\controller;

class Update extends Base
{
    public function index()
    {
        return $this->fetch('admin@test/index');
    }

    public function step1($file='')
    {
        return $this->legacyUpdateDisabled();
    }

    public function step2()
    {
        return $this->legacyUpdateDisabled();
    }

    public function step3()
    {
        // r7 repair (sweep): the last step of the retired online update only emptied the caches, from a plain GET link
        // (no page links it). Clear cache lives in the top bar (POST + admin token).
        return $this->legacyUpdateDisabled();
    }

    public function one()
    {
        return $this->legacyUpdateDisabled();
    }

    private function legacyUpdateDisabled()
    {
        return $this->error('Online updates are disabled for this customized installation. Use a reviewed local upgrade with a verified backup and compatibility tests.');
    }
}
