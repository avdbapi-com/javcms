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
namespace app\common\util;

/**
 * Statement class of the front-end database connection (owner: lane H; DbTimeout::hookFramework, verify r2 O-F1).
 *
 * Before every statement the request's database deadline is checked (DbTimeout::beforeStatement): the statement may run
 * at most for the time this request has left (the session limit is lowered once less than the tier limit remains), and
 * once the time is spent it fails at once with a statement timeout (SQLSTATE 70100), so the page answers with its stale
 * copy or the lighter page instead of running one more 3 s statement after another. ThinkPHP prepares and executes every
 * query through this class (PDO::ATTR_STATEMENT_CLASS); fetching is PDOStatement's own. No constructor: PDO refuses a
 * statement class with a public one, and PDOStatement has none to call.
 */
class DbDeadlineStatement extends \PDOStatement
{
    /**
     * @param array|null $params
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function execute($params = null)
    {
        DbTimeout::beforeStatement();
        return parent::execute($params);
    }
}
