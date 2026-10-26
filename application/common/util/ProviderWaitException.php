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
