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

/**
 * One politeness clock per provider, shared by web steps, scheduler callbacks and the CLI (XVID-SCALE-BRIEF D-I6).
 * Backed by the row `avdb_provider_state` (provider PK), so two runners cannot double the request rate.
 *
 * - slot: next_request_at = GREATEST(now, next_request_at) + interval, read and written under a row lock;
 *   only CLI runners sleep until their slot, a web step that finds the clock busy is told when to come back;
 * - adaptive: an answer slower than 5 s doubles the interval (max 60 s), 20 fast answers in a row lower it 10 %
 *   (never below the provider's floor: xvid 5 s bulk / 2 s interactive, AVDB 2 s / 1 s);
 * - 429: Retry-After goes to retry_at for the whole provider;
 * - 5xx, timeouts, cURL 6/7/28/35/52/56: back off 60 s x 2^(n-1), max 1 h, the job stays running; after 8 failures in
 *   a row the verdict is "pause" with a readable message;
 * - Cloudflare challenge: the provider pauses 1 h with "The provider asks for a browser check ...";
 * - storage errors (StorageGuard::trip): verdict "storage" (the caller pauses the job AND the schedule, no retry).
 *
 * decide() is pure (unit-tested); acquire/success/failure apply it to the stored row. A store object can be injected
 * for tests: methods read(string $provider): array and transact(string $provider, callable $fn): mixed, where $fn gets
 * the current row and returns [newRow, result].
 */
class ProviderPacer
{
    const SLOW_MS = 5000;
    const MAX_INTERVAL_MS = 60000;
    /** Added to every slot: requests of two processes reach the provider with a few ms of jitter, never below the floor. */
    const SLOT_GUARD_MS = 50;
    const FAST_RUN = 20;
    const RECOVER = 0.9;
    const BACKOFF_BASE = 60;
    const BACKOFF_MAX = 3600;
    const PAUSE_AFTER = 8;
    const CHALLENGE_PAUSE = 3600;
    const TABLE = 'avdb_provider_state';
    const COLUMNS = ['next_request_at', 'interval_ms', 'failures', 'fast_count', 'retry_at', 'last_status', 'last_ms', 'last_error',
        'cli_seen_at', 'newest_remote_time', 'rate_items_s', 'updated_at'];

    private $provider;
    private $mode;
    private $floorBulk;
    private $floorMode;
    private $store;
    /** Test hooks: clock() returns float seconds, sleeper(float seconds) sleeps; cli overrides PHP_SAPI detection. */
    public $clock;
    public $sleeper;
    public $cli;

    /**
     * @param string     $provider    registry key
     * @param string     $mode        'bulk' | 'interactive'
     * @param array|null $minInterval ['bulk'=>ms,'interactive'=>ms]; null = from the provider's capabilities
     * @param object     $store       optional state store (tests)
     */
    public function __construct($provider, $mode = 'bulk', ?array $minInterval = null, $store = null)
    {
        if (!is_string($provider) || !preg_match('/^[a-z0-9_]{1,20}$/D', $provider)) { throw new \InvalidArgumentException('Invalid provider key.'); }
        if (!in_array($mode, ['bulk', 'interactive'], true)) { throw new \InvalidArgumentException('Invalid pacing mode.'); }
        if ($minInterval === null) { $minInterval = ImportProviders::get($provider)->capabilities()['min_interval_ms']; }
        $this->provider = $provider;
        $this->mode = $mode;
        $this->floorBulk = max(100, (int)($minInterval['bulk'] ?? 5000));
        $this->floorMode = $mode === 'bulk' ? $this->floorBulk : max(100, (int)($minInterval['interactive'] ?? $this->floorBulk));
        $this->store = $store;
    }

    public function provider() { return $this->provider; }

    public static function defaults($floorMs)
    {
        return ['next_request_at' => 0.0, 'interval_ms' => (int)$floorMs, 'failures' => 0, 'fast_count' => 0, 'retry_at' => 0, 'last_status' => 0,
            'last_ms' => 0, 'last_error' => '', 'cli_seen_at' => 0, 'newest_remote_time' => 0, 'rate_items_s' => 0.0, 'updated_at' => 0];
    }

    /** 60, 120, 240, 480, 960, 1920, 3600, 3600 ... seconds. */
    public static function backoff($failures)
    {
        $n = max(1, (int)$failures);
        if ($n >= 7) { return self::BACKOFF_MAX; }
        return (int)min(self::BACKOFF_MAX, self::BACKOFF_BASE * (2 ** ($n - 1)));
    }

    /** Interval for this runner: the stored adaptive interval, scaled down for interactive previews, never below the floor. */
    public static function effectiveInterval(array $row, $floorBulk, $floorMode)
    {
        $iv = max((int)$floorBulk, (int)$row['interval_ms']);
        if ($floorMode < $floorBulk) { $iv = (int)round($iv * $floorMode / $floorBulk); }
        return max((int)$floorMode, $iv);
    }

    /**
     * Pure decision. $outcome: ['kind'=>'ok','ms'=>int,'status'=>int] | ['kind'=>'rate_limit','retry_after'=>int]
     * | ['kind'=>'transient','status'=>int,'message'=>string] | ['kind'=>'challenge'] | ['kind'=>'storage','message'=>..]
     * | ['kind'=>'data','message'=>..]. Returns ['row'=>new row, 'verdict'=>ok|retry|pause|provider_paused|storage|fail,
     * 'retry_at'=>int, 'message'=>string].
     */
    public static function decide(array $row, array $outcome, $floorMs, $now)
    {
        $row += self::defaults($floorMs);
        $now = (float)$now; $t = (int)floor($now);
        $row['updated_at'] = $t;
        $kind = (string)($outcome['kind'] ?? 'data');
        $msg = substr((string)($outcome['message'] ?? ''), 0, 255);
        if ($kind === 'ok') {
            $ms = max(0, (int)($outcome['ms'] ?? 0));
            $row['failures'] = 0; $row['last_status'] = (int)($outcome['status'] ?? 200); $row['last_ms'] = $ms; $row['last_error'] = '';
            if ($ms > self::SLOW_MS) {
                $row['interval_ms'] = min(self::MAX_INTERVAL_MS, max((int)$floorMs, (int)$row['interval_ms']) * 2); $row['fast_count'] = 0;
            } else {
                $row['fast_count'] = (int)$row['fast_count'] + 1;
                if ($row['fast_count'] >= self::FAST_RUN) {
                    $row['interval_ms'] = max((int)$floorMs, (int)round((int)$row['interval_ms'] * self::RECOVER)); $row['fast_count'] = 0;
                }
            }
            return ['row' => $row, 'verdict' => 'ok', 'retry_at' => 0, 'message' => ''];
        }
        if ($kind === 'rate_limit') {
            $wait = max(1, min(3600, (int)($outcome['retry_after'] ?? 60)));
            $row['retry_at'] = max((int)$row['retry_at'], $t + $wait); $row['last_status'] = 429; $row['fast_count'] = 0;
            $row['last_error'] = ImportProviders::text('provider_429', 'The provider asked to wait {s} seconds (HTTP 429).', ['s' => $wait]);
            return ['row' => $row, 'verdict' => 'retry', 'retry_at' => $row['retry_at'], 'message' => $row['last_error']];
        }
        if ($kind === 'transient') {
            $row['failures'] = (int)$row['failures'] + 1; $row['fast_count'] = 0;
            $row['last_status'] = (int)($outcome['status'] ?? 0);
            $row['retry_at'] = $t + self::backoff($row['failures']);
            $row['last_error'] = $msg !== '' ? $msg : ImportProviders::text('provider_no_answer', 'The provider did not answer.');
            if ($row['failures'] >= self::PAUSE_AFTER) {
                $text = ImportProviders::text('provider_failed', 'The provider failed {n} times in a row (last: {last}). The import is paused; resume it when the provider works again.', ['n' => $row['failures'], 'last' => $row['last_error']]);
                return ['row' => $row, 'verdict' => 'pause', 'retry_at' => $row['retry_at'], 'message' => substr($text, 0, 255)];
            }
            return ['row' => $row, 'verdict' => 'retry', 'retry_at' => $row['retry_at'], 'message' => $row['last_error']];
        }
        if ($kind === 'challenge') {
            $row['retry_at'] = max((int)$row['retry_at'], $t + self::CHALLENGE_PAUSE); $row['fast_count'] = 0;
            $row['last_status'] = (int)($outcome['status'] ?? 0);
            $row['last_error'] = ImportProviders::text('provider_challenge', 'The provider asks for a browser check; its API must be exempt from the challenge.');
            return ['row' => $row, 'verdict' => 'provider_paused', 'retry_at' => $row['retry_at'], 'message' => $row['last_error']];
        }
        if ($kind === 'storage') {
            return ['row' => $row, 'verdict' => 'storage', 'retry_at' => 0, 'message' => $msg !== '' ? $msg : ImportProviders::text('storage_full_short', 'Database or disk is full.')];
        }
        return ['row' => $row, 'verdict' => 'fail', 'retry_at' => 0, 'message' => $msg];
    }

    /** Map an exception to a decide() outcome. */
    public static function classify(\Throwable $e)
    {
        if (class_exists(StorageGuard::class) && StorageGuard::isStorageError($e)) { return ['kind' => 'storage', 'message' => 'Database or disk is full: ' . substr($e->getMessage(), 0, 180)]; }
        if ($e instanceof ProviderHttpException) {
            switch ($e->kind) {
                case ProviderHttpException::RATE_LIMIT: return ['kind' => 'rate_limit', 'retry_after' => $e->retryAfter, 'status' => 429];
                case ProviderHttpException::CHALLENGE: return ['kind' => 'challenge', 'status' => $e->status];
                case ProviderHttpException::TRANSIENT: return ['kind' => 'transient', 'status' => $e->status, 'message' => $e->getMessage()];
                default: return ['kind' => 'data', 'status' => $e->status, 'message' => $e->getMessage()];
            }
        }
        if ($e instanceof AvdbClientException && (int)$e->getCode() === 429) { return ['kind' => 'rate_limit', 'retry_after' => (int)$e->getRetryAfter(), 'status' => 429]; }
        $m = $e->getMessage();
        if (preg_match('/\(HTTP (5[0-9]{2}|408)\)/', $m, $x)) { return ['kind' => 'transient', 'status' => (int)$x[1], 'message' => $m]; }
        if (preg_match('/\(cURL ([0-9]{1,3})\)/', $m, $x) && in_array((int)$x[1], ProviderHttp::TRANSIENT_CURL, true)) { return ['kind' => 'transient', 'status' => 0, 'message' => $m]; }
        return ['kind' => 'data', 'status' => 0, 'message' => $m];
    }

    private function now() { return $this->clock ? (float)call_user_func($this->clock) : microtime(true); }

    private function isCli() { return $this->cli !== null ? (bool)$this->cli : PHP_SAPI === 'cli'; }

    /**
     * Take the next request slot. CLI: sleeps until the slot and returns the seconds slept. Web: never sleeps; when the
     * clock is busy it throws ProviderWaitException(retry_at) without taking a slot. A provider-wide retry_at (429,
     * backoff, challenge) throws ProviderWaitException for every runner.
     */
    public function acquire()
    {
        $now = $this->now(); $cli = $this->isCli();
        $floorBulk = $this->floorBulk; $floorMode = $this->floorMode;
        $result = $this->transact(function (array $row) use ($now, $cli, $floorBulk, $floorMode) {
            if ((int)$row['retry_at'] > $now) { return [null, ['retry_at' => (int)$row['retry_at'], 'reason' => (string)$row['last_error'], 'wait' => -1]]; }
            $next = (float)$row['next_request_at'];
            if (!$cli && $next > $now + 0.001) { return [null, ['retry_at' => (int)ceil($next), 'reason' => 'pace', 'wait' => -1]]; }
            $start = max($now, $next);
            $row['next_request_at'] = $start + (self::effectiveInterval($row, $floorBulk, $floorMode) + self::SLOT_GUARD_MS) / 1000;
            $row['updated_at'] = (int)$now;
            return [$row, ['retry_at' => 0, 'reason' => '', 'wait' => $start - $now]];
        });
        if ($result['wait'] < 0) { throw new ProviderWaitException($this->provider, $result['retry_at'], $result['reason']); }
        if ($result['wait'] > 0) {
            if ($this->sleeper) { call_user_func($this->sleeper, $result['wait']); }
            else { usleep((int)round(min(120.0, $result['wait']) * 1000000)); }
        }
        return $result['wait'];
    }

    /**
     * Take the slot only when it is free now (prefetch, D-I1): never sleeps, never throws for the pace; returns false
     * when the clock is busy or the provider is waiting (retry_at). Used to start the next window while a batch applies.
     */
    public function tryAcquire()
    {
        $now = $this->now(); $floorBulk = $this->floorBulk; $floorMode = $this->floorMode;
        return (bool)$this->transact(function (array $row) use ($now, $floorBulk, $floorMode) {
            if ((int)$row['retry_at'] > $now || (float)$row['next_request_at'] > $now + 0.001) { return [null, false]; }
            $row['next_request_at'] = $now + (self::effectiveInterval($row, $floorBulk, $floorMode) + self::SLOT_GUARD_MS) / 1000;
            $row['updated_at'] = (int)$now;
            return [$row, true];
        });
    }

    /**
     * The request of the slot just taken leaves now: when it leaves later than its slot (a slow process, a busy
     * machine), the next slot moves so the provider still sees the full interval after the real send time.
     */
    public function markSent($at = null)
    {
        $at = $at === null ? $this->now() : (float)$at; $floorBulk = $this->floorBulk; $floorMode = $this->floorMode;
        return $this->transact(function (array $row) use ($at, $floorBulk, $floorMode) {
            $min = $at + (self::effectiveInterval($row, $floorBulk, $floorMode) + self::SLOT_GUARD_MS) / 1000;
            if ($min <= (float)$row['next_request_at']) { return [null, false]; }
            $row['next_request_at'] = $min;
            return [$row, true];
        });
    }

    /** Last verdict recorded by this instance (success/failure/apply): ['verdict','retry_at','message'] or null. */
    public $last;

    public function success($ms, $status = 200)
    {
        return $this->apply(['kind' => 'ok', 'ms' => (int)$ms, 'status' => (int)$status]);
    }

    /** Record a failed request; returns the decide() verdict array (without the row). */
    public function failure(\Throwable $e)
    {
        return $this->apply(self::classify($e));
    }

    public function apply(array $outcome)
    {
        $now = $this->now(); $floor = $this->floorBulk;
        $this->last = $this->transact(function (array $row) use ($outcome, $now, $floor) {
            $d = self::decide($row, $outcome, $floor, $now);
            $row = $d['row']; unset($d['row']);
            return [$row, $d];
        });
        return $this->last;
    }

    /** CLI heartbeat (brief 2.2: written by every tools/import.php --run/--tick). */
    public function heartbeat()
    {
        $now = (int)$this->now();
        return $this->transact(function (array $row) use ($now) { $row['cli_seen_at'] = $now; return [$row, $now]; });
    }

    public function setNewestRemoteTime($ts)
    {
        $ts = max(0, (int)$ts);
        return $this->transact(function (array $row) use ($ts) { if ($ts > (int)$row['newest_remote_time']) { $row['newest_remote_time'] = $ts; } return [$row, (int)$row['newest_remote_time']]; });
    }

    public function setRate($itemsPerSecond)
    {
        $rate = max(0.0, min(100000.0, round((float)$itemsPerSecond, 1)));
        return $this->transact(function (array $row) use ($rate) { $row['rate_items_s'] = $rate; return [$row, $rate]; });
    }

    /** Clear a provider pause (admin "resume provider"). */
    public function clearRetry()
    {
        return $this->transact(function (array $row) { $row['retry_at'] = 0; $row['failures'] = 0; $row['last_error'] = ''; return [$row, true]; });
    }

    public function state()
    {
        if ($this->store) { return $this->store->read($this->provider) + self::defaults($this->floorBulk); }
        return self::dbRead($this->provider) + self::defaults($this->floorBulk);
    }

    /** Newest CLI heartbeat over all providers (0 = never). Never installs the schema. */
    public static function cliSeenAt()
    {
        try {
            $v = \think\Db::name(self::TABLE)->max('cli_seen_at');
            return max(0, (int)$v);
        } catch (\Throwable $e) { return 0; }
    }

    /** All stored rows keyed by provider (read only; [] before the schema exists). */
    public static function rows()
    {
        try {
            $out = [];
            foreach ((array)\think\Db::name(self::TABLE)->select() as $row) { $out[(string)$row['provider']] = $row; }
            return $out;
        } catch (\Throwable $e) { return []; }
    }

    private function transact(callable $fn)
    {
        if ($this->store) { return $this->store->transact($this->provider, $fn); }
        $provider = $this->provider; $floor = $this->floorBulk;
        $table = self::table();
        \think\Db::execute('INSERT IGNORE INTO `' . $table . '` (`provider`,`interval_ms`,`updated_at`) VALUES (?,?,?)', [$provider, $floor, time()]);
        \think\Db::startTrans();
        try {
            $row = \think\Db::name(self::TABLE)->where('provider', $provider)->lock(true)->find();
            $row = (is_array($row) ? $row : []) + self::defaults($floor);
            list($new, $result) = $fn($row);
            if (is_array($new)) {
                $set = [];
                foreach (self::COLUMNS as $c) { if (array_key_exists($c, $new)) { $set[$c] = $c === 'last_error' ? substr((string)$new[$c], 0, 255) : $new[$c]; } }
                \think\Db::name(self::TABLE)->where('provider', $provider)->update($set);
            }
            \think\Db::commit();
            return $result;
        } catch (\Throwable $e) {
            \think\Db::rollback();
            throw $e;
        }
    }

    private static function dbRead($provider)
    {
        try {
            $row = \think\Db::name(self::TABLE)->where('provider', $provider)->find();
            return is_array($row) ? $row : [];
        } catch (\Throwable $e) { return []; }
    }

    private static function table()
    {
        $prefix = (string)config('database.prefix');
        if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix)) { throw new \RuntimeException('Invalid database prefix.'); }
        return $prefix . self::TABLE;
    }
}
