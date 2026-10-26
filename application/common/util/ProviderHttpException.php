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

/** Error of ProviderHttp / provider answer validation; `kind` tells ProviderPacer::classify what to do. */
class ProviderHttpException extends \RuntimeException
{
    const RATE_LIMIT = 'rate_limit';
    const TRANSIENT = 'transient';
    const CHALLENGE = 'challenge';
    const TOO_LARGE = 'too_large';
    const MALFORMED = 'malformed';
    const HTTP = 'http';
    const NETWORK = 'network';

    public $kind;
    public $status;
    public $retryAfter;
    public $curlErrno;

    public function __construct($message, $kind, $status = 0, $retryAfter = 0, $curlErrno = 0)
    {
        parent::__construct($message, (int)$status);
        $this->kind = (string)$kind;
        $this->status = (int)$status;
        $this->retryAfter = (int)$retryAfter;
        $this->curlErrno = (int)$curlErrno;
    }

    public function getKind() { return $this->kind; }
    public function getRetryAfter() { return $this->retryAfter; }
}
