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
 * C11 (owner: lane H). Recognize a database statement timeout, and build the per-connection statement limit.
 * XVID-SCALE-BRIEF 3.2 C11, 2.10 L6, sc-S3a, CS M1, HP-H-3.
 *
 *   is(\Throwable $e): bool  true when $e (or an exception in its getPrevious() chain) is SQLSTATE 70100 or carries a
 *                            driver code in {1969, 3024, 1317, 188, 1028}:
 *                            1969 MariaDB max_statement_time exceeded, 188 MariaDB interrupted in the FULLTEXT decode
 *                            phase, 3024 MySQL max_execution_time exceeded, 1317 query interrupted, 1028 sort aborted.
 *
 * seen(): bool             true once is() recognized a timeout in this request (EdgeCache::shed() then answers with the
 *                            stale copy or the lighter page, never the busy page: owner decision 2026-10-17).
 *
 * Recognized carriers: \PDOException (errorInfo), think\exception\PDOException (getData()['PDO Error Info']; ThinkPHP
 * does not chain the original), mysqli_sql_exception (getCode()), and as a last resort the PDO message format
 * "SQLSTATE[xxxxx]: <text>: <code> <text>". Callers never retry after a database error in visitor paths (D-S5).
 *
 * Statement limit (HP-H-3): initCommand($seconds) returns the PDO init command, one statement valid on every engine:
 *   SET @jv_stl=N /*!50708 , SESSION max_execution_time=N000 * / /*M!100101 , SESSION max_statement_time=N * /
 *   MariaDB >= 10.1.1          max_statement_time=N
 *   MySQL >= 5.7.8             max_execution_time=N000 (SELECT statements only, by MySQL's design)
 *   MariaDB < 10.1.1, MySQL < 5.7.8: no limit (the command only sets the variable), never an error.
 * With an explicit $version the engine-specific single form is returned (tools that know the server, tests).
 * initAttribute() is the PDO attribute number chosen by PHP version (Pdo\Mysql::ATTR_INIT_COMMAND on PHP 8.4+, the old
 * constant before; the old constant is deprecated on 8.5).
 *
 * Per-request deadline (verify r2 O-F1, owner decision 2026-10-17: statement limit on, graceful fallbacks): a front-end
 * request may spend at most its statement limit + DEADLINE_GRACE - RENDER_RESERVE seconds (from REQUEST_TIME_FLOAT) in the
 * database, not the limit once per statement, so the page (or the lighter page that replaces it) is answered within the
 * statement limit + DEADLINE_GRACE (flood-r4 O-F1: the time before the first statement, a crawler's wait for a slot, and
 * the render after the last one are inside that bound). hookFramework($limit) (Base::__construct) puts
 * DbDeadlineStatement on the framework connection; before every statement beforeStatement() keeps the session limit at
 * or under the time left (capCommand(); one SET per CAP_STEP of request time at most) and, once the time is spent, fails
 * the statement at once with a statement timeout (SQLSTATE 70100, code 1969) so the page answers with its stale copy or
 * the lighter page (EdgeCache::shed() / lite()). startBackground($seconds) gives a job that runs after the response was
 * finished (the page-view import tick, flood-r4 N17) its own database time, its statements still limited to the
 * statement limit. Housekeeping web slices use capCommand() / lockWaitCommand() on their own connection
 * (ScaleSchema::capSession).
 */
final class DbTimeout
{
    public const SQLSTATE = '70100';
    public const CODES = [1969, 3024, 1317, 188, 1028];
    /** A front-end request: statement limit + this many seconds in all (O-F1). */
    public const DEADLINE_GRACE = 1.0;
    /**
     * flood-r4 O-F1: the database time of a front-end request ends this long before statement limit + DEADLINE_GRACE, so the
     * render after the last statement (the full page, or the lighter page after a refused statement) still ends inside the
     * bound (2 of 258,082 flood requests ran 4.00-4.23 s with a 3 s limit: a crawler's slot wait came first, the render after).
     * flood-r7 R7-3: 0.3 -> 0.5 s (2 of 294,041 N16 flood requests on 2 busy cores ended 4.10-4.12 s: deep tag pages that spent
     * the whole database time, then 0.4 s for the lighter page).
     */
    public const RENDER_RESERVE = 0.5;
    /** Less than this left: the statement is not sent (it fails at once as a statement timeout). */
    public const MIN_LEFT = 0.05;
    /** Smallest session limit set (seconds; 0 would mean "no limit" on every engine). */
    public const MIN_CAP = 0.1;
    /**
     * Each SET of the session limit leaves this much room under the time left (one SET per CAP_STEP of request time at most,
     * never one per statement), so every statement ends by the deadline.
     */
    public const CAP_STEP = 0.25;

    private static $version = null;
    private static $seen = false;
    private static $deadline = null;   // absolute microtime() of this request's database deadline (null = none)
    private static $cap = null;        // session limit of the framework connection now (seconds), as far as we set it
    private static $maxCap = null;     // highest session limit beforeStatement() sets (startBackground: the statement limit)
    private static $limit = 0;         // statement limit of this request (hookFramework), 0 = none
    private static $hooked = false;

    public static function is(\Throwable $e): bool
    {
        for ($depth = 0; $e !== null && $depth < 8; $depth++, $e = $e->getPrevious()) {
            if (self::matches($e)) {
                self::$seen = true;
                return true;
            }
        }
        return false;
    }

    /** true when is() recognized a statement timeout earlier in this request (the time-limit fallback follows). */
    public static function seen(): bool
    {
        return self::$seen;
    }

    /** Test hook. */
    public static function resetSeen(): void
    {
        self::$seen = false;
    }

    // ----- per-request deadline (verify r2 O-F1) -----

    /**
     * Front-end requests (Base::__construct): deadline = REQUEST_TIME_FLOAT + $stmtLimit + DEADLINE_GRACE - RENDER_RESERVE,
     * and the framework connection gets DbDeadlineStatement (PDO::ATTR_STATEMENT_CLASS: on the open link, else in the
     * connection's params before it connects). $stmtLimit <= 0 = no statement limit configured: no deadline either. Returns
     * true when the hook is in place; never throws (a persistent PDO link refuses the attribute: then statements keep the
     * plain limit).
     */
    public static function hookFramework(int $stmtLimit): bool
    {
        if (self::$hooked) {
            return true;
        }
        // inside the framework the Db facade may still be unloaded (no query yet): autoload it there, never outside
        if ($stmtLimit <= 0 || !class_exists('\think\Db', defined('THINK_VERSION'))) {
            return false;
        }
        $start =isset($_SERVER['REQUEST_TIME_FLOAT']) && is_numeric($_SERVER['REQUEST_TIME_FLOAT']) ? (float)$_SERVER['REQUEST_TIME_FLOAT'] : microtime(true);
        try {
            if (!class_exists(DbDeadlineStatement::class)) {
                return false;
            }
            $conn = \think\Db::connect();
            $attr = [DbDeadlineStatement::class];
            $pdo = $conn->getPdo();
            if ($pdo instanceof \PDO) {
                $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, $attr);
            } else {
                $all = $conn->getConfig();
                $params = is_array($all) && isset($all['params']) && is_array($all['params']) ? $all['params'] : [];
                $params[\PDO::ATTR_STATEMENT_CLASS] = $attr;
                $conn->setConfig('params', $params);
            }
            self::setDeadline($start + $stmtLimit + self::DEADLINE_GRACE - self::RENDER_RESERVE, (float)$stmtLimit);
            self::$limit = $stmtLimit;
            self::$hooked = true;
        } catch (\Throwable $e) {
            self::$hooked = false;
        }
        return self::$hooked;
    }

    /**
     * Set (or clear with null) the request deadline and the session limit the connection starts with (tests, tools, a deadline
     * renewed for a few statements and restored: lane P's step epilogue). The ceiling of a background job (startBackground:
     * its statements never above the statement limit) stays for the rest of the request.
     */
    public static function setDeadline(?float $at, ?float $sessionCap = null): void
    {
        self::$deadline = $at;
        self::$cap = $sessionCap;
    }

    /**
     * flood-r4 N17 (HO-P-R3-1): a job that runs in this request AFTER its response was finished (the page-view import tick:
     * Housekeeping::importRun) gets its own database time: deadline = now + $seconds + DEADLINE_GRACE instead of the
     * visitor's (statement limit + 1 s from the request start, mostly spent by then: background steps were cut mid-batch and
     * kept their 300 s lease). Its statements stay limited to this request's statement limit: the next statement sets the
     * session limit again (the visitor's deadline may have lowered it), at most to the statement limit and never past the
     * new deadline. Only when the request has a deadline (front end with a statement limit); returns false otherwise (no
     * deadline: nothing to lift). Never throws.
     */
    public static function startBackground(float $seconds): bool
    {
        if (self::$deadline === null) {
            return false;
        }
        self::$deadline = microtime(true) + max(0.0, $seconds) + self::DEADLINE_GRACE;
        self::$cap = null;
        self::$maxCap = self::$limit > 0 ? (float)self::$limit : null;
        return true;
    }

    /** Seconds of database time this request has left (null = no deadline). */
    public static function remaining(): ?float
    {
        return self::$deadline === null ? null : self::$deadline - microtime(true);
    }

    /** The session limit as last set for this request (null = unknown / none). */
    public static function sessionCap(): ?float
    {
        return self::$cap;
    }

    /**
     * Called by DbDeadlineStatement::execute() before every statement of the framework connection. Time spent -> throws a
     * statement timeout (SQLSTATE 70100 / 1969; is() and seen() then say so). A session limit above the time left (or not
     * known) -> one SET lowers it to the time left - CAP_STEP (at least MIN_CAP, at most the background job's statement
     * limit), so this and every later statement end by the deadline (flood-r4 O-F1: before, a statement could run up to
     * CAP_STEP past it) and the next SET follows CAP_STEP later at the earliest.
     */
    public static function beforeStatement(): void
    {
        if (self::$deadline === null) {
            return;
        }
        $left = self::$deadline - microtime(true);
        if ($left <= self::MIN_LEFT) {
            self::$seen = true;
            throw self::spentException();
        }
        if (self::$cap !== null && self::$cap <= $left) {
            return;
        }
        $cap = max(self::MIN_CAP, floor(($left - self::CAP_STEP) * 100) / 100);
        if (self::$maxCap !== null) {
            $cap = min($cap, max(self::MIN_CAP, self::$maxCap));
        }
        // remembered first: a SET that fails is not tried again before every statement
        self::$cap = $cap;
        try {
            $pdo = class_exists('\think\Db', false) ? \think\Db::connect()->getPdo() : false;
            if ($pdo instanceof \PDO) {
                $pdo->exec(self::capCommand($cap));
            }
        } catch (\Throwable $e) {
        }
    }

    /** The statement timeout raised for a request whose database time is spent (recognized by is()). */
    public static function spentException(): \PDOException
    {
        $e = new \PDOException('SQLSTATE[70100]: Query execution was interrupted: 1969 Query execution was interrupted (request time limit reached)');
        $e->errorInfo = ['70100', 1969, 'Query execution was interrupted (request time limit reached)'];
        return $e;
    }

    /**
     * One statement that sets the session statement limit to $seconds (fractions allowed, at least MIN_CAP): MariaDB
     * >= 10.1.1 max_statement_time, MySQL >= 5.7.8 max_execution_time in ms (SELECT statements only, by MySQL's design),
     * older servers only @jv_stl. Engine from $version, else the cached server version, else the comment form that every
     * engine accepts.
     */
    public static function capCommand(float $seconds, ?string $version = null): string
    {
        $s = max(self::MIN_CAP, min(3600.0, $seconds));
        $ms = max(1, (int)ceil($s * 1000));
        $sec = rtrim(rtrim(sprintf('%.3F', $s), '0'), '.');
        $v = $version === null ? self::cachedVersion() : $version;
        $p = $v !== '' ? self::parseVersion($v) : ['kind' => '', 'id' => 0];
        if ($p['kind'] === 'mariadb') {
            return $p['id'] >= 100101 ? 'SET SESSION max_statement_time=' . $sec : 'SET @jv_stl=' . $sec;
        }
        if ($p['kind'] === 'mysql') {
            return $p['id'] >= 50708 ? 'SET SESSION max_execution_time=' . $ms : 'SET @jv_stl=' . $sec;
        }
        return 'SET @jv_stl=' . $sec . ' /*!50708 , SESSION max_execution_time=' . $ms . ' */ /*M!100101 , SESSION max_statement_time=' . $sec . ' */';
    }

    /** Row-lock and metadata-lock waits of the session limited to $seconds (whole seconds, at least 1; every engine). */
    public static function lockWaitCommand(int $seconds): string
    {
        $s = max(1, min(31536000, $seconds));
        return 'SET SESSION innodb_lock_wait_timeout=' . $s . ', SESSION lock_wait_timeout=' . $s;
    }

    /** Test hook: forget the hook and the deadline of this request. */
    public static function resetDeadline(): void
    {
        self::$deadline = null;
        self::$cap = null;
        self::$maxCap = null;
        self::$limit = 0;
        self::$hooked = false;
    }

    /** PDO attribute for the init command (value 1002 on every PHP version) without touching the deprecated constant on 8.4+. */
    public static function initAttribute(): int
    {
        if (class_exists('Pdo\\Mysql') && defined('Pdo\\Mysql::ATTR_INIT_COMMAND')) {
            return (int)constant('Pdo\\Mysql::ATTR_INIT_COMMAND');
        }
        return defined('PDO::MYSQL_ATTR_INIT_COMMAND') ? (int)constant('PDO::MYSQL_ATTR_INIT_COMMAND') : 1002;
    }

    /**
     * The init command for a limit of $seconds (0 = no limit: the unlimited form for CLI / housekeeping).
     * Without $version: one statement for every engine (measured on MariaDB 5.5, 10.1, 10.3, 10.6, 10.11, 11.4 and MySQL
     * 5.6, 5.7, 8.0): MySQL >= 5.7.8 runs the /*!50708 part, MariaDB >= 10.1.1 runs the /*M!100101 part, MariaDB ignores
     * MySQL 5.7+ version comments, older servers run neither and only set @jv_stl. So no cached version can ever make a
     * connection fail (a cached MySQL form sent to MariaDB after a server change would be error 1193 on every connect).
     */
    public static function initCommand(int $seconds, ?string $version = null): string
    {
        $seconds = max(0, min(3600, $seconds));
        if ($version === null) {
            return 'SET @jv_stl=' . $seconds . ' /*!50708 , SESSION max_execution_time=' . ($seconds * 1000) . ' */ /*M!100101 , SESSION max_statement_time=' . $seconds . ' */';
        }
        $v = self::parseVersion($version);
        if ($v['kind'] === 'mariadb') {
            return $v['id'] >= 100101 ? 'SET SESSION max_statement_time=' . $seconds : 'SET @jv_stl=' . $seconds;
        }
        if ($v['kind'] === 'mysql') {
            return $v['id'] >= 50708 ? 'SET SESSION max_execution_time=' . ($seconds * 1000) : 'SET @jv_stl=' . $seconds;
        }
        // Unknown: MariaDB runs the /*M! part (10.1.1+), MySQL ignores it.
        return 'SET @jv_stl=' . $seconds . ' /*M!100101 , SESSION max_statement_time=' . $seconds . ' */';
    }

    /** Statement that removes the limit on an existing connection (housekeeping's own connection, tools). */
    public static function unlimitedCommand(?string $version = null): string
    {
        return self::initCommand(0, $version);
    }

    /** ['kind' => 'mariadb'|'mysql'|'', 'id' => 100611|80036|0] from a VERSION() string. */
    public static function parseVersion(string $version): array
    {
        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $m)) {
            return ['kind' => '', 'id' => 0];
        }
        $id = (int)$m[1] * 10000 + (int)$m[2] * 100 + (int)$m[3];
        if (stripos($version, 'mariadb') !== false) {
            // "5.5.5-10.6.9-MariaDB" (old replication prefix) and "10.6.9-MariaDB"
            if (preg_match('/^5\.5\.5-(\d+)\.(\d+)\.(\d+)/', $version, $n)) {
                $id = (int)$n[1] * 10000 + (int)$n[2] * 100 + (int)$n[3];
            }
            return ['kind' => 'mariadb', 'id' => $id];
        }
        return ['kind' => 'mysql', 'id' => $id];
    }

    public static function cachedVersion(): string
    {
        if (self::$version === null) {
            $data = class_exists(ScaleTier::class) ? ScaleTier::readJson(ScaleTier::runtime('scale') . 'dbver.json') : null;
            self::$version = is_array($data) && isset($data['version']) && is_string($data['version']) ? $data['version'] : '';
        }
        return self::$version;
    }

    public static function rememberVersion(string $version): void
    {
        $version = substr(trim($version), 0, 64);
        if ($version === '' || $version === self::cachedVersion()) {
            return;
        }
        self::$version = $version;
        ScaleTier::writeAtomic(ScaleTier::runtime('scale') . 'dbver.json', json_encode(['version' => $version, 'at' => time()]));
    }

    /** Test hook. */
    public static function reset(?string $version = null): void
    {
        self::$version = $version;
    }

    private static function matches(\Throwable $e): bool
    {
        if ($e instanceof \PDOException && is_array($e->errorInfo)) {
            if (self::hit($e->errorInfo[0] ?? '', $e->errorInfo[1] ?? 0)) {
                return true;
            }
        }
        if ($e instanceof \think\exception\PDOException && method_exists($e, 'getData')) {
            $data = $e->getData();
            $info = is_array($data) && isset($data['PDO Error Info']) && is_array($data['PDO Error Info']) ? $data['PDO Error Info'] : [];
            if (self::hit($info['SQLSTATE'] ?? '', $info['Driver Error Code'] ?? 0)) {
                return true;
            }
        }
        if ($e instanceof \mysqli_sql_exception) {
            if (self::hit(method_exists($e, 'getSqlState') ? $e->getSqlState() : '', $e->getCode())) {
                return true;
            }
        }
        $code = $e->getCode();
        if (is_string($code) && $code === self::SQLSTATE) {
            return true;
        }
        if (preg_match('/SQLSTATE\[([0-9A-Z]{5})\]:[^:]*:\s*(\d{1,5})\b/', (string)$e->getMessage(), $m)) {
            return self::hit($m[1], $m[2]);
        }
        if (preg_match('/SQLSTATE\[70100\]/', (string)$e->getMessage())) {
            return true;
        }
        return false;
    }

    private static function hit($sqlState, $driverCode): bool
    {
        if ((string)$sqlState === self::SQLSTATE) {
            return true;
        }
        return is_numeric($driverCode) && in_array((int)$driverCode, self::CODES, true);
    }
}
