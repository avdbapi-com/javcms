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
 * C8 (owner: lane H). Buffered visitor counters, folded into {pre}vod by housekeeping at most once per 5 min.
 * XVID-SCALE-BRIEF 3.2 C8, D-L11, L8, se-R14.
 *
 *   add(int $vodId, string $kind): void
 *   $kind: 'hits' (one view; day/week/month are applied at fold time with today's reset rules), 'up', 'down' (digg),
 *   'score:N' with N 1-10 (one rating, contracts interpretation I3, confirmed by lane H). Unknown kinds and ids < 1 are
 *   ignored, never an error; ids above MAX(vod_id) (cached 10 minutes) are dropped; the buffer holds at most 200,000 rows
 *   (beyond that only rows already buffered are increased); a database error drops the increment (a view counter is not
 *   worth a retry or an error page). The direct UPDATE of 4 indexed counters (11-86 ms at 7 M rows) and the list-cache
 *   wipe of today's Ajax::hits disappear once lane S applies HO-H-2.
 *
 * fold(PDO, prefix, opt): housekeeping folds the buffer into the video table every 5 minutes, in short transactions of
 * CHUNK rows with a kept cursor, so a web slice never runs one statement past its budget (below; verify r2 R2-9).
 */
final class HitBuffer
{
    public const MAX_ROWS = 200000;
    public const MAXID_TTL = 600;
    public const COUNT_TTL = 10;
    public const CAP_ZONE = 100000;    // from half the cap on the count is re-read every ZONE_TTL seconds
    public const CAP_MARGIN = 20000;   // new rows stop this far below MAX_ROWS (absorbs rows added between two count reads)
    public const ZONE_TTL = 1;
    public const FOLD_GAP = 300;       // at most one fold pass per 5 minutes (D-L11)
    /** Buffer rows per fold transaction (verify r2 R2-9: one whole-buffer transaction outlived PHP's 50 s web limit). */
    public const CHUNK = 2000;
    /** The same inside a visitor request that cannot finish its response first (a short slice). */
    public const CHUNK_INLINE = 200;
    /** Seconds a fold statement may run after the slice deadline (statement limit = time left + this). */
    public const CAP_GRACE = 2.0;

    public static function add(int $vodId, string $kind): void
    {
        if ($vodId < 1) {
            return;
        }
        $n = [0, 0, 0, 0, 0]; // hits, up, down, score sum, score count
        if ($kind === 'hits') {
            $n[0] = 1;
        } elseif ($kind === 'up') {
            $n[1] = 1;
        } elseif ($kind === 'down') {
            $n[2] = 1;
        } elseif (preg_match('/^score:([1-9]|10)$/D', $kind, $m)) {
            $n[3] = (int)$m[1];
            $n[4] = 1;
        } else {
            return;
        }
        try {
            $q = ScaleTier::queryFn(null, null, true);
            if ($q === null) {
                return;
            }
            [$select, $pre, $exec] = $q;
            if ($vodId > self::maxId($select, $pre)) {
                return;
            }
            $now = time();
            $set = 'n_hits = n_hits + ?, n_up = n_up + ?, n_down = n_down + ?, n_score = n_score + ?, n_score_num = n_score_num + ?, at = ?';
            // The count is cached (COUNT_TTL, ZONE_TTL once half full); new rows stop CAP_MARGIN below the cap, so the rows added
            // between two count reads never pass MAX_ROWS below about 10,000 new videos a second (a flood beyond the gates).
            if (self::rows($select, $pre) >= self::MAX_ROWS - self::CAP_MARGIN) {
                $exec('UPDATE ' . $pre . 'hit_buffer SET ' . $set . ' WHERE vod_id = ?', [$n[0], $n[1], $n[2], $n[3], $n[4], $now, $vodId]);
                return;
            }
            $exec('INSERT INTO ' . $pre . 'hit_buffer (vod_id, n_hits, n_up, n_down, n_score, n_score_num, at) VALUES (?, ?, ?, ?, ?, ?, ?)'
                . ' ON DUPLICATE KEY UPDATE ' . $set,
                [$vodId, $n[0], $n[1], $n[2], $n[3], $n[4], $now, $n[0], $n[1], $n[2], $n[3], $n[4], $now]);
        } catch (\Throwable $e) {
            // dropped: never an error page, never a retry
        }
    }

    /**
     * Housekeeping (D-L11, L8): fold the buffer into {pre}vod, one pass at most once per FOLD_GAP seconds
     * (runtime/scale/fold.json), with the reset rules of Ajax::hits applied at fold time: month = same calendar month as
     * vod_time_hits, week = Sunday-to-Saturday week of today, day = same date; otherwise the period restarts at the
     * buffered count. up/down/score_num/score_all are added and vod_score is recomputed (one decimal). A pass walks the
     * buffer in vod_id order in transactions of $opt['chunk'] rows (CHUNK, verify r2 R2-9): the chunk's rows are locked
     * (a visitor hit for a locked row waits for that short commit), applied and deleted by their ids. With $opt['deadline']
     * (web slices) the pass stops between chunks at the deadline, every statement is capped at the time left + CAP_GRACE
     * (ScaleSchema::capSession) and the next run continues from the kept cursor (fold.json 'cursor'), before the gap is
     * looked at. No cache is cleared (no Cache::clear('vod'), no clearListCache()). Paused while StorageGuard::isFull(); a
     * storage error trips StorageGuard and is never retried here; a statement timeout keeps the cursor for the next run.
     * $opt: now, force (ignore the gap), deadline (microtime, null = none), chunk.
     * Returns ['state' => 'folded'|'empty'|'partial'|'not_due'|'paused'|'error', 'rows' => n, 'hits' => n, 'chunks' => n, 'ms' => ...].
     */
    public static function fold(\PDO $pdo, string $prefix, array $opt = []): array
    {
        $t0 = microtime(true);
        if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix)) {
            return ['state' => 'error', 'error' => 'invalid prefix'];
        }
        $now = (int)($opt['now'] ?? time());
        $stateFile = ScaleTier::runtime('scale') . 'fold.json';
        $state = ScaleTier::readJson($stateFile);
        $state = is_array($state) ? $state : [];
        $cursor = max(0, (int)($state['cursor'] ?? 0));
        // a pass in progress continues in the next run; a new pass starts FOLD_GAP after the last finished one
        if ($cursor === 0 && empty($opt['force']) && $now - (int)($state['at'] ?? 0) < self::FOLD_GAP) {
            return ['state' => 'not_due', 'next' => (int)($state['at'] ?? 0) + self::FOLD_GAP];
        }
        $deadline = isset($opt['deadline']) && $opt['deadline'] !== null ? (float)$opt['deadline'] : null;
        $chunk = max(1, min(50000, (int)($opt['chunk'] ?? self::CHUNK)));
        if (class_exists(__NAMESPACE__ . '\StorageGuard') && method_exists(__NAMESPACE__ . '\StorageGuard', 'isFull')) {
            try {
                if (StorageGuard::isFull()) {
                    return ['state' => 'paused', 'why' => 'storage'];
                }
            } catch (\Throwable $e) {
            }
        }
        $p = $prefix;
        $d = getdate($now);
        $dayStart = mktime(0, 0, 0, $d['mon'], $d['mday'], $d['year']);
        $weekStart = $dayStart - $d['wday'] * 86400;
        $weekEnd = mktime(23, 59, 59, $d['mon'], $d['mday'], $d['year']) + (6 - $d['wday']) * 86400;
        $monthStart = mktime(0, 0, 0, $d['mon'], 1, $d['year']);
        $monthEnd = mktime(0, 0, 0, $d['mon'] + 1, 1, $d['year']) - 1;
        $dayEnd = $dayStart + 86399;
        // a pass that a deadline or a timeout cut short keeps its counts across runs
        $rows = $cursor > 0 ? (int)($state['pass_rows'] ?? 0) : 0;
        $hits = $cursor > 0 ? (int)($state['pass_hits'] ?? 0) : 0;
        $chunks = 0;
        $finished = false;
        $save = function (array $extra = []) use (&$state, $stateFile, &$cursor, &$rows, &$hits) {
            $state['cursor'] = $cursor;
            $state['pass_rows'] = $rows;
            $state['pass_hits'] = $hits;
            ScaleTier::writeAtomic($stateFile, json_encode($extra + $state));
        };
        try {
            while (true) {
                if ($deadline !== null) {
                    if (microtime(true) >= $deadline) {
                        break;
                    }
                    if (class_exists(__NAMESPACE__ . '\ScaleSchema')) {
                        ScaleSchema::capSession($pdo, $deadline + self::CAP_GRACE);
                    }
                }
                $pdo->beginTransaction();
                $st = $pdo->prepare('SELECT vod_id, n_hits FROM ' . $p . 'hit_buffer WHERE vod_id > ? ORDER BY vod_id LIMIT ' . $chunk . ' FOR UPDATE');
                $st->execute([$cursor]);
                $locked = $st->fetchAll(\PDO::FETCH_NUM);
                if (!$locked) {
                    $pdo->commit();
                    $finished = true;
                    break;
                }
                $ids = [];
                $chunkHits = 0;
                foreach ($locked as $r) {
                    $ids[] = (int)$r[0];
                    $chunkHits += (int)$r[1];
                }
                $in = implode(',', $ids);
                // 1) counters, using the stored vod_time_hits of each video (not yet changed in this statement)
                $pdo->prepare('UPDATE ' . $p . 'vod v JOIN ' . $p . 'hit_buffer b ON b.vod_id = v.vod_id SET'
                    . ' v.vod_hits_day = IF(b.n_hits = 0, v.vod_hits_day, IF(v.vod_time_hits BETWEEN ? AND ?, v.vod_hits_day + b.n_hits, b.n_hits)),'
                    . ' v.vod_hits_week = IF(b.n_hits = 0, v.vod_hits_week, IF(v.vod_time_hits BETWEEN ? AND ?, v.vod_hits_week + b.n_hits, b.n_hits)),'
                    . ' v.vod_hits_month = IF(b.n_hits = 0, v.vod_hits_month, IF(v.vod_time_hits BETWEEN ? AND ?, v.vod_hits_month + b.n_hits, b.n_hits)),'
                    . ' v.vod_hits = v.vod_hits + b.n_hits, v.vod_up = v.vod_up + b.n_up, v.vod_down = v.vod_down + b.n_down,'
                    . ' v.vod_score_num = v.vod_score_num + b.n_score_num, v.vod_score_all = v.vod_score_all + b.n_score'
                    . ' WHERE b.vod_id IN (' . $in . ')')
                    ->execute([$dayStart, $dayEnd, $weekStart, $weekEnd, $monthStart, $monthEnd]);
                // 2) the view time and the average, from the values written by statement 1
                $pdo->prepare('UPDATE ' . $p . 'vod v JOIN ' . $p . 'hit_buffer b ON b.vod_id = v.vod_id SET'
                    . ' v.vod_time_hits = IF(b.n_hits > 0, ?, v.vod_time_hits),'
                    . ' v.vod_score = IF(b.n_score_num > 0 AND v.vod_score_num > 0, ROUND(v.vod_score_all / v.vod_score_num, 1), v.vod_score)'
                    . ' WHERE b.vod_id IN (' . $in . ')')
                    ->execute([$now]);
                $pdo->exec('DELETE FROM ' . $p . 'hit_buffer WHERE vod_id IN (' . $in . ')');
                $pdo->commit();
                $chunks++;
                $rows += count($ids);
                $hits += $chunkHits;
                $cursor = (int)end($ids);
                if (count($ids) < $chunk) {
                    $finished = true;
                    break;
                }
                $save();
            }
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                try { $pdo->rollBack(); } catch (\Throwable $ignored) {}
            }
            $timeout = class_exists(__NAMESPACE__ . '\DbTimeout') && DbTimeout::is($e);
            $tripped = false;
            if (!$timeout && class_exists(__NAMESPACE__ . '\StorageGuard') && method_exists(__NAMESPACE__ . '\StorageGuard', 'trip')) {
                try { $tripped = (bool)StorageGuard::trip($e); } catch (\Throwable $ignored) {}
            }
            $msg = substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 200);
            if ($timeout) {
                // the slice's time is up inside a chunk: the chunk was rolled back, the next run continues at the cursor
                $save(['error' => $msg]);
                return ['state' => 'partial', 'rows' => $rows, 'hits' => $hits, 'chunks' => $chunks, 'timeout' => true,
                    'ms' => (int)round((microtime(true) - $t0) * 1000)];
            }
            // a failed fold waits for the next due time (no retry loop); the chunks already committed stay folded
            $cursor = 0;
            $rows = 0;
            $hits = 0;
            $save(['at' => $now, 'error' => $msg]);
            return ['state' => $tripped ? 'paused' : 'error', 'error' => substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 300)];
        }
        $ms = (int)round((microtime(true) - $t0) * 1000);
        if (!$finished) {
            $save();
            return ['state' => 'partial', 'rows' => $rows, 'hits' => $hits, 'chunks' => $chunks, 'cursor' => $cursor, 'ms' => $ms];
        }
        $folds = (int)($state['folds'] ?? 0) + ($rows > 0 ? 1 : 0);
        ScaleTier::writeAtomic($stateFile, json_encode(['at' => $now, 'rows' => $rows, 'hits' => $hits, 'ms' => $ms, 'folds' => $folds,
            'chunks' => $chunks, 'cursor' => 0]));
        ScaleTier::writeAtomic(ScaleTier::runtime('scale') . 'hitbuf.json', json_encode(['rows' => 0, 'at' => time()]));
        return $rows > 0 ? ['state' => 'folded', 'rows' => $rows, 'hits' => $hits, 'chunks' => $chunks, 'ms' => $ms, 'folds' => $folds]
            : ['state' => 'empty', 'rows' => 0, 'hits' => 0, 'ms' => $ms];
    }

    /** MAX(vod_id), cached for 10 minutes (primary key read). */
    private static function maxId(callable $select, string $pre): int
    {
        $file = ScaleTier::runtime('scale') . 'maxid.json';
        $c = ScaleTier::readJson($file);
        if (is_array($c) && isset($c['max'], $c['at']) && time() - (int)$c['at'] < self::MAXID_TTL) {
            return (int)$c['max'];
        }
        $r = $select('SELECT MAX(vod_id) AS m FROM ' . $pre . 'vod');
        $max = $r ? (int)$r[0]['m'] : 0;
        ScaleTier::writeAtomic($file, json_encode(['max' => $max, 'at' => time()]));
        return $max;
    }

    /** Row count of the buffer, cached for COUNT_TTL seconds (ZONE_TTL from half the cap on). */
    private static function rows(callable $select, string $pre): int
    {
        $file = ScaleTier::runtime('scale') . 'hitbuf.json';
        $c = ScaleTier::readJson($file);
        if (is_array($c) && isset($c['rows'], $c['at'])
            && time() - (int)$c['at'] < ((int)$c['rows'] >= self::MAX_ROWS - self::CAP_ZONE ? self::ZONE_TTL : self::COUNT_TTL)) {
            return (int)$c['rows'];
        }
        $r = $select('SELECT COUNT(*) AS c FROM ' . $pre . 'hit_buffer');
        $rows = $r ? (int)$r[0]['c'] : 0;
        ScaleTier::writeAtomic($file, json_encode(['rows' => $rows, 'at' => time()]));
        return $rows;
    }
}
