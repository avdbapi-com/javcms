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

/** Thrown by ProviderPacer::acquire() when the provider clock is busy (web) or the provider is paused (retry_at). */
class ProviderWaitException extends \RuntimeException
{
    public $provider;
    public $retryAt;
    public $reason;

    public function __construct($provider, $retryAt, $reason)
    {
        $this->provider = (string)$provider; $this->retryAt = (int)$retryAt; $this->reason = (string)$reason;
        parent::__construct($reason === 'pace' ? 'Waiting for the provider pace slot.' : ($reason !== '' ? $reason : 'The provider is paused for a moment.'));
    }
}
