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
namespace app\common\model;

use think\Db;
use app\common\util\AvdbClient;
use app\common\util\AvdbProvider;
use app\common\util\ImportProvider;
use app\common\util\ImportProviders;
use app\common\util\ProviderHttpException;
use app\common\util\ProviderPacer;
use app\common\util\ProviderWaitException;
use app\common\util\StorageGuard;
use app\common\util\XvidClient;
use app\common\validate\Vod as VodValidate;

/**
 * Explicit POST-only durable importer; read methods never install its schema.
 *
 * 1.2.0 (XVID-SCALE-BRIEF 3.4 step 7-8, lane P): one job engine for every registered provider (ImportProviders). The
 * provider of a job is `avdb_import_job.provider` (old jobs: 'avdb'); the engine branches on capabilities. Discovery
 * strategies: selected ids, AVDB export cursor / pages (unchanged), id windows (stable_cursor id_windows, D-I2), category
 * and recent pages (D-I3), incremental h-sync (D-I7) and the presence sweep with recheck and breaker (D-I8). Rows pass
 * the provider's normalize() (2.4 field and host rules) and the hash skip (D-I4) before they become items; tombstones
 * (D-I9) and the update anomaly (D-I8b) are honoured; cross-provider JAV codes map to the existing video (2.5 `skip`);
 * vod_reurl = <provider>:<remote id> (D-P6); new rows are placed by D-I12. Scopes above 50,000 run in bulk mode, from
 * the admin page and from the command line alike (D-I5, XVID-NOLIMIT-BRIEF A3: no web size limit; a web job continues
 * by itself through tickWeb()). The whole xvid catalog reads the xvidexport snapshot (mode snapshot), else the keyset
 * export API, else id windows; the storage target (StorageGuard::importTarget) is the only stop (A2). Transient provider errors keep the job running with retry_at (D-I6); storage errors pause the job and the
 * schedule (D-I15). Jobs of providers other than 'avdb' are stored with the states x_running / x_paused and the
 * `_vod_cleanup_sealed` marker 'provider:<key>', which 1.1.0's state machine never runs (D-I14).
 */
class AvdbImport
{
    private $client;
    private $images;
    private $schemaReady = false;
    private $playerKeys;
    private $catalogDirty = false;
    /** Video ids saved in this batch, for IndexNow (SEO-ADMIN-BRIEF E.3, HO-S6). */
    private $seoPingIds = [];
    private $categoryAccessDirty = false;
    private $stepDeadline;
    private $scheduleContext;
    /** Provider instances by key (injected for tests / the mock, otherwise built from the registry). */
    private $providers = [];
    private $pacerFactory;
    private $clock;
    /** Vod ids written in the current batch (FtIndex::touch at the batch end). */
    private $touched = [];
    /** fp-port-c: search-queue ids of the open bulk chunk (written ascending right before its commit); null = no chunk open. */
    private $ftqChunk = null;
    /** fp-port-b: updated vod ids of the open bulk chunk (FilterVocab::touched right after the chunk's commit); null = no chunk open. */
    private $ftvChunk = null;
    /** A2: the last storage-target check found the job close to the target (check every batch, fresh measurement). */
    private $nearTarget = false;
    /** Lowest vod_time and the type ids of the rows placed in the current batch (ListPageMap::dirty at the batch end, P7). */
    private $placed = ['min' => null, 'types' => []];
    /** Batches processed by this process (storage check every 20 bulk batches). */
    private $batchNo = 0;
    /** import-r2 N7: videos this process inserted (the storage-target prediction) and the last exact measurement of this process. */
    private $insertedRows = 0;
    private $tgt = null;
    /** Tests: float clock of the storage-target measurements (default microtime). */
    private $tgtClock = null;
    /**
     * import-r3 N7: [unix time, inserted videos] of this process at its storage checks (at most TARGET_SAMPLES), so the
     * videos inserted since the shared (lagging) measurement was taken can be added to it (predictedDb()).
     */
    private $tgtSamples = [];
    /** import-r2 N2: a discovery call asks the step to end now (not enough step time left for a shard download). */
    private $stepYield = false;
    /** Bulk mode of the job being stepped. */
    private $bulk = false;
    /**
     * Round-4 N17 (work admission): small batches for this step (a web caller with little database time); seconds per row of the
     * last batch, seconds of the last discovery page and of the last end-of-batch work of this process; units committed in this
     * step; the chunk / discovery page in flight [start, rows] (a cut one is measured from it).
     */
    private $smallBatches = false;
    private $lastSpr = null;
    private $lastDiscSec = null;
    private $postSec = null;
    private $stepUnits = 0;
    private $inFlight = null;
    /** Contract calls of this process (evidence for tests; bounded). */
    public static $trace = [];
    private const PROVIDER = 'avdb';
    public const RUN_STATES = ['running', 'x_running'];
    public const PAUSE_STATES = ['paused', 'x_paused'];
    /** D-I5: scopes above this many videos run in bulk mode (web and CLI jobs, XVID-NOLIMIT-BRIEF A3). */
    public const BULK_MIN = 50000;
    /** A3 tickWeb(): at most one background step per TICK_EVERY seconds, only when nobody stepped the job for TICK_IDLE. */
    public const TICK_EVERY = 30;
    public const TICK_IDLE = 60;
    /**
     * Round-3 verify fix (flood-r3 N17 / N18): lane H's page-view claim (Housekeeping::importTrigger) moves import.due by whole
     * seconds (time() - filemtime >= 30), so a claim can reach tickWeb() 29.x s after the last decision; the sub-second check
     * against TICK_EVERY wasted it and the next claim came 30 s later (4 of 12 ticks in N17, 3 of 10 in N18). Web callers accept
     * a claim TICK_SLACK early; the claim itself keeps the 30 s spacing.
     */
    public const TICK_SLACK = 1.0;
    /**
     * Round-3 verify fix (flood-r3 N17): a busy server (both deep gate slots taken, or the load above 1.5 x the CPUs) defers a
     * background step at most TICK_BUSY_MAX seconds after the job last moved (the end of the last background step or any other
     * step of the job); then the step runs anyway with TICK_BUSY_BUDGET seconds of work (it runs after the response, outside
     * every gate slot). A sustained deep-page flood holds the single deep slot of a 2 vCPU host at every tick: no step ran in
     * 6 minutes (0 rows) before this rule.
     */
    public const TICK_BUSY_MAX = 240;
    public const TICK_BUSY_BUDGET = 3.0;
    public const BULK_TX = 50;
    /** D-I15 guard (0.9) and the A2 storage target: checked every STORAGE_EVERY bulk batches and before each discovery page. */
    public const STORAGE_EVERY = 20;
    /** A2: near the storage target the measurement is renewed when older than this many seconds. */
    public const TARGET_NEAR_S = 5;
    /** D-I8b: more than 20 % of a batch (at least 20 rows) with a new title or embed URL pauses the job. */
    public const ANOMALY_RATIO = 0.2;
    public const ANOMALY_MIN = 20;
    /** 2.4: more than 5 % of a batch's posters dropped by the image-host rule pauses the job. */
    public const IMAGE_DROP_RATIO = 0.05;
    /** se-R11b: a job stops at estimate x 1.2 videos. */
    public const SIZE_BOUND = 1.2;
    /** D-I12: placement "as new arrivals" when the site holds at least this many videos of another provider. */
    public const PLACEMENT_MIN_OTHER = 1000;
    /** D-I8: presence sweep windows, recheck delay, breaker. */
    public const PRESENCE_WINDOW = 500;
    public const RECHECK_AFTER = 86400;
    public const HIDE_MAX_RATIO = 0.02;
    public const HIDE_MAX = 5000;
    /** D-I13: videos deleted per undo step; above this share of the search documents the index is rebuilt instead. */
    public const UNDO_STEP = 500;
    /**
     * A paused bulk job keeps the search-index bulk window open (FtIndex skips queued ids >= its start) at most this
     * long; then closeIdleBulkWindows() closes it (bulkEnd) and the resume opens a new window above the rows already
     * indexed (verify fix engines F13 / import bulk-cancel-search-frozen).
     */
    public const BULK_IDLE_CLOSE = 600;
    /**
     * Round-2 verify fix R2-7b (engines-r2): a request that must stay short (a job start, CLI or web, the end of a web job, the
     * admin's cancel / undo / remove-all) never runs the sorted append of a bulk window (C1 bulkEnd over the window: minutes for
     * a large window; 100 s per 1,000 docs on MySQL 8.0 before lane S's R2-7a). Such a "light" close appends inline only up to
     * LIGHT_APPEND_MAX published videos; a larger window that the search index would append (as new arrivals, <= 10 % of the
     * docs) closes at once and its append moves to the command line (_bulk_append, tools/import.php --run / --tick:
     * runDeferredAppends(), resumable after a kill); a site without a command-line runner in 24 h queues those videos for the
     * budgeted drain (up to LIGHT_QUEUE_MAX, the limit of lane S's orphan-window net); anything else is rebuild_due, which is
     * what bulkEnd itself decides for it. An append the command line has not run after DEFER_MAX_AGE is queued the same way.
     */
    public const LIGHT_APPEND_MAX = 1000;
    public const LIGHT_QUEUE_MAX = 200000;
    public const DEFER_MAX_AGE = 3600;
    /** Share of the search docs above which a bulk window is rebuilt instead of appended (FtIndex::bulkEnd, D-I5). */
    public const APPEND_DOC_RATIO = 0.10;
    /** import-r2 N8: a step's work ends this many seconds before its budget, counted from the request start (web), so the whole request ends within the budget. */
    public const STEP_END_RESERVE = 0.5;
    /**
     * Round-3 verify fix (N18 re-measure): a step under lane H's per-request database deadline ends its work this many seconds
     * before it (stepDeadlineDb); a background tick with less than TICK_DB_MIN seconds of that database time left does not step
     * ('db_time': the next claim steps).
     */
    public const STEP_DB_RESERVE = 0.5;
    public const TICK_DB_MIN = 1.5;
    /**
     * Round-4 verify fix (flood-r4 N17): a step admits its work unit by unit. A batch starts only when its expected time (rows x
     * the job's measured seconds per row x STEP_SAFETY, at least STEP_BATCH_MIN_S) ends before the work deadline (stepDeadline /
     * stepDeadlineDb); otherwise the batch is made smaller (at least STEP_BATCH_MIN_ROWS rows) or the step ends. Inside a batch
     * every chunk is admitted the same way with STEP_POST_S (at least the measured end-of-batch work) kept for the batch end; a
     * discovery page needs its measured time x STEP_SAFETY (at least STEP_BATCH_MIN_S), the job end STEP_FINISH_MIN_S. A web
     * caller with less than STEP_SMALL_DB_S of database time at the step start (lane H's per-request deadline) uses
     * STEP_SMALL_BATCH-row batches and snapshot pages. STEP_SPR_DEFAULT = seconds per row before the job measured its own (N18:
     * 500 rows in 1.0-1.5 s). Before: the deadline was checked only after each batch / 50-row chunk, one chunk under the flood
     * ran past the 0.5 s reserve, its statements were refused, the lease release too, and every later tick met job_waiting.
     */
    public const STEP_BATCH_MIN_S = 1.0;
    public const STEP_SAFETY = 1.5;
    public const STEP_SPR_DEFAULT = 0.004;
    public const STEP_BATCH_MIN_ROWS = 10;
    public const STEP_POST_S = 0.3;
    public const STEP_FINISH_MIN_S = 2.0;
    public const STEP_SMALL_DB_S = 3.0;
    public const STEP_SMALL_BATCH = 100;
    /**
     * Round-4 verify fix (flood-r4 N17): the end of a step (the stepFailed() write, the catalog flush, the lease release and the
     * status answer) runs with database time of its own when less than min(STEP_EPILOGUE_DB_S, the tier statement limit) is
     * left under lane H's per-request deadline: the deadline is renewed for those short statements (DbTimeout sets the session
     * statement limit to the time left again, so the statement limit still applies) and restored afterwards. A cut step never
     * keeps its 300 s lease. A step cut by the database time limit keeps the job running and is never an item failure (the cut
     * unit was not committed; the next step does it again, with the cut unit's time learned); from the second cut step in a row
     * without progress the next step waits TICK_EVERY x 2^(n-2) seconds, at most DB_CUT_BACKOFF_MAX (a busy database is not
     * asked again at once; the job is never paused for it).
     */
    public const STEP_EPILOGUE_DB_S = 2.0;
    public const DB_CUT_BACKOFF_MAX = 600;
    /**
     * Round-4 verify fix (import-r4 snapshot re-import feed loss): items of these states are applied again with a change-feed
     * row of the same video (the newer row is applied last); 'created' / 'updated' keep their rollback images (_requeue).
     */
    public const REQUEUE_STATES = ['pending', 'created', 'updated', 'skipped', 'failed'];
    /** import-r2 N2: a shard download call needs at least this much step time left (else the next step downloads). */
    public const SNAP_DOWNLOAD_MIN_S = 2.0;
    /** import-r2 N2: back-off before the next try of a shard that came back short or with a bad sha256 (doubles per try, at most 60 s). */
    public const SNAP_RETRY_S = 5.0;
    /**
     * import-r2 N1 / N3 / N6 (D-I8b for change feeds): every row of the snapshot 'changes' phase and of the hourly 'feed' mode is a
     * change by definition, so the per-page 20 % rule paused every whole-catalog import (N3: 5 admin resumes for 5,000 changes).
     * Feed pages are measured against the mapped catalog instead: the job pauses when this provider's visible changes (a new title
     * or player of an existing video) on feed pages within FEED_ANOMALY_WINDOW exceed min(FEED_ANOMALY_MAX, max(FEED_ANOMALY_FLOOR,
     * FEED_ANOMALY_RATIO x the provider's mapped videos)); changes the admin accepted (resume) no longer count. The per-batch rule
     * stays for every other page (recent(h) batches, shards, keyset, id windows). Thresholds: owner decision pending.
     */
    public const FEED_ANOMALY_RATIO = 0.2;
    public const FEED_ANOMALY_MAX = 50000;
    public const FEED_ANOMALY_FLOOR = 1000;
    public const FEED_ANOMALY_WINDOW = 86400;
    /**
     * import-r2 N7 (storage target at 0.92 x the limit): information_schema sizes follow the InnoDB statistics, which lag behind a
     * bulk import (626,699 videos: 819 MB read, 938.8 MB after ANALYZE TABLE). Once the measured size passes TARGET_APPROACH of
     * the database target, the import measures exactly (StorageGuard::refreshExact: ANALYZE of the growing tables) every
     * TARGET_EXACT_S seconds, predicts the size between those measurements from its own inserted rows x the measured bytes per
     * row (TARGET_DEFAULT_KB until two exact measurements TARGET_BPR_MIN_ROWS apart exist), checks every batch, confirms with an
     * exact measurement at most TARGET_NEAR_S old, and stops before the batch that would pass the target. TARGET_APPROACH was
     * the round-2 start of the exact path (lagging size >= 0.7 x target); since round 3 targetExactDue() decides (kept for callers
     * of earlier builds).
     */
    public const TARGET_APPROACH = 0.7;
    public const TARGET_EXACT_S = 60;
    public const TARGET_DEFAULT_KB = 3.0;
    public const TARGET_BPR_MIN_ROWS = 2000;
    /**
     * Round-3 verify fix (import-r3 N7, 0.1 GB limit: base 54 MB, target 81 MB): the lagging measurement went from 54 MB (below
     * TARGET_APPROACH x 81) to 86 MB (past the target) between two checks, so the exact path never ran and the job ended at
     * 0.885 x the limit (0.90 x after the closing search step: guard full, next start refused). The decision now uses the
     * predicted size (the shared measurement + the videos this process inserted since it was taken x the larger of
     * TARGET_DEFAULT_KB and the measured size per video, predictedDb()), and the exact path (targetDb) starts from the first
     * batch whenever the predicted gap to the target is below max(TARGET_EXACT_GAP_MB, TARGET_EXACT_GAP_RATIO x target)
     * (targetExactDue()); a 'reached' read from the lagging measurement is confirmed by an exact measurement before the stop.
     */
    public const TARGET_EXACT_GAP_MB = 256;
    public const TARGET_EXACT_GAP_RATIO = 0.3;
    public const TARGET_SAMPLES = 64;
    /**
     * import-r3 N7: the measured MB per video (slope between this process's first exact measurement and the newest) replaces the
     * default only once the two are TARGET_BPR_MIN_MB apart: below that the reported size has moved by a few whole extents only
     * (each index segment takes 1 MB at a time), and an early low step made the prediction run short (0.1 GB limit: 1.395 KB
     * measured over 14,000 videos against 1.73 KB real).
     */
    public const TARGET_BPR_MIN_MB = 32.0;
    /**
     * import-r2 N7: margin below the target: InnoDB grows a table by whole extents (1-4 MB per index at a time), so the exact size
     * can jump by a few MB between two measurements, and the job end writes a little more (measured: an exact 819 MB at the stop
     * of a 1 GB limit became 820.4 MB with a 1 MB margin). The stop keeps max(TARGET_MARGIN_MB, TARGET_MARGIN_RATIO x target).
     * import-r3 N7: 8 MB (was 4): the index segments of javcms_vod (about 20 B-trees) take their 1 MB extents at nearly the same
     * time, so with a 0.1 GB limit the exact size went 66 -> 74 -> 81 MB for 7,500 / 6,500 / 2,000 more videos (+7 MB for 3.4 MB
     * of rows in the last interval): with 4 MB the stop was confirmed at 81 MB of an 81 MB target (job end 0.80 x).
     */
    public const TARGET_MARGIN_MB = 8.0;
    public const TARGET_MARGIN_RATIO = 0.005;
    public const UNDO_REBUILD_RATIO = 0.05;
    /** IMP 6.1 Grouped preset (names of the provider's categories, case-insensitive; '_' = ' '); the rest goes to "Other". */
    public const GROUPED = [
        ['Amateur & Homemade', ['Amateur', 'Real Amateur', 'Cam Porn', 'Solo Masturbation']],
        ['Asian', ['Asian Woman', 'Japanese', 'Indian', 'Arab']],
        ['Big Tits & Big Ass', ['BBW', 'Big Ass', 'Big Tits', 'Ass']],
        ['Oral', ['Blowjob', 'Facial', 'Cumshot', 'Bukkake', 'Ass to Mouth']],
        ['Hardcore', ['Anal', 'Gangbang', 'Orgy', 'Gapes', 'Fisting', 'Creampie', 'Interracial', 'Big Cock']],
        ['Lesbian', ['Lesbian']],
        ['MILF & Mature', ['MILF', 'Mature']],
        ['Teen 18+', ['Teen']],
        ['Fetish & BDSM', ['BDSM', 'Femdom', 'Feet', 'Heels', 'Stockings', 'Lingerie', 'Oiled', 'Pissing', 'Sex Dolls', 'Sex Toys', 'Cuckold', 'Swingers', 'Workout']],
        ['Gay', ['Gay']],
        ['Trans', ['Shemale', 'Bi Sexual']],
        ['Animated & AI', ['Toons', 'AI', 'Virtual Reality', 'ASMR']],
        ['Other', []],
    ];
    /** 2.2 / 2.6: never created as categories (provider name, placeholder). */
    public const NEVER_CREATE = ['xvidapi', 'unknown'];
    /** D5 (shipped default): unticked unless the admin ticks them. */
    public const DEFAULT_OFF = ['gay', 'shemale', 'bi sexual'];
    /**
     * Journal retention (keeps the import journal small on every install): items of running / paused jobs, every
     * pending or failed item and all items of the newest KEEP_FULL_RUNS finished jobs keep their details (retry and
     * rollback); every other item keeps only its small columns (job_id, remote_id, vod_id, state, message, locale_cursor).
     */
    public const KEEP_FULL_RUNS = 3;
    /**
     * History bound: finished jobs older than the newest KEEP_HISTORY_RUNS (the import screen lists the newest 30 jobs,
     * so older ones can be neither opened, retried nor resumed there) are removed completely: all their items, pending
     * and failed ones included, then the job row. Their per-state counts are written to options._journal_counts first
     * (status() shows them while a removal is unfinished). Without this the small columns, every failed item's payload
     * and translations and one job row per run would grow forever. Running / paused jobs never count and stay.
     */
    public const KEEP_HISTORY_RUNS = 30;
    public const TRIMMED_MESSAGE = 'The details of this import were removed after 3 newer imports, so it can no longer be undone.';
    /** Work bound of one automatic trimHistory() call (seconds); leftovers continue at the next call. */
    public const TRIM_SECONDS = 3;
    public const FINISHED_JOB_STATES = ['complete','cancelled','rolled_back','rollback_conflict'];
    public const TRIM_ITEM_STATES = ['created','updated','skipped','rolled_back','rollback_conflict'];
    private const GROUPS = [
        'text'=>['vod_name','vod_en','vod_blurb','vod_content'],
        'metadata'=>['vod_actor','vod_director','vod_writer','vod_class','vod_tag','vod_area','vod_lang','vod_year','vod_state','vod_remarks','vod_pubdate','vod_serial','vod_total','vod_isend','vod_duration'],
        'images'=>['vod_pic','vod_pic_thumb','vod_pic_slide','vod_pic_screenshot'],
        'playback'=>['vod_play_from','vod_play_url','vod_play_server','vod_play_note'],
    ];
    /** Extra fields a provider's normalize() may set outside GROUPS (xvid quality badge, D13). */
    private const EXTRA_FIELDS = ['vod_version' => 30];
    public function __construct($client = null,$images = null) { $this->client = $client ?: new AvdbClient(); $this->images=$images; }
    /** Use this provider instance for its key (tests, the mock provider, tools/import.php). */
    public function withProvider(ImportProvider $provider) { $this->providers[$provider->key()] = $provider; return $this; }
    /** Tests: callable(string $provider, string $mode): ProviderPacer. */
    public function setPacerFactory(?callable $factory) { $this->pacerFactory = $factory; return $this; }
    /** Tests: injected clock (unix seconds) for placement, presence and recheck times. */
    public function setClock(?callable $clock) { $this->clock = $clock; return $this; }
    private function now() { return $this->clock ? (int)call_user_func($this->clock) : time(); }
    private function json($value) { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
    private function decode($value) { return $value ? (json_decode($value, true) ?: []) : []; }
    private static function trace($what) { if (count(self::$trace) < 5000) { self::$trace[] = $what; } }
    private function ready()
    {
        if ($this->schemaReady) { return true; }
        try { foreach (['avdb_import_job','avdb_import_item','avdb_import_map','avdb_translation'] as $table) { Db::name($table)->limit(1)->select(); } $this->schemaReady=true; return true; }
        catch (\Throwable $e) { return false; }
    }
    public function ensureSchema()
    {
        if (!$this->ready()) {
            $prefix = (string)config('database.prefix');
            if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix)) { throw new \RuntimeException('Invalid database prefix.'); }
            $sql = file_get_contents(APP_PATH.'install/sql/avdb-import.sql');
            foreach (explode(';', str_replace('javcms_', $prefix, $sql)) as $statement) {
                if (trim($statement) !== '') { Db::execute($statement); }
            }
            $this->schemaReady=true;
        }
        self::upgradeSchema();
    }
    /** Columns / indexes / tables added by avdb-import-upgrade.sql (XVID-SCALE-BRIEF D-P5), checked once per process. */
    private static $upgradeDone = false;
    /**
     * D-P5 upgrade for installs made before 1.2.0: additive, idempotent and code-guarded. Each column / index is added
     * only when information_schema shows it missing. All missing columns and indexes of one table go into ONE
     * ALTER TABLE (verify fix engines F11: four ALTERs on avdb_import_map copied the table four times on MySQL 5.7,
     * 16 + 10 + 10 + 5 s), tried with ALGORITHM=INSTANT, then ALGORITHM=INPLACE, LOCK=NONE (writes keep working during
     * the rebuild), then without an algorithm clause (MariaDB < 10.3, MySQL 5.6). One runner at a time (GET_LOCK); a
     * concurrent runner waits up to 10 s; when the combined statement meets an already-applied change (1060 / 1061 /
     * 1050) the parts run one by one and those count as done. Returns a log of what ran (empty when nothing to do).
     * Called by ensureSchema() (first importer POST) and by tools/import.php --upgrade / tools/upgrade.php.
     */
    public static function upgradeSchema()
    {
        if (self::$upgradeDone) { return []; }
        $prefix = (string)config('database.prefix');
        if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix)) { throw new \RuntimeException('Invalid database prefix.'); }
        $todo = self::upgradeTodo($prefix);
        if (!$todo) { self::$upgradeDone = true; return []; }
        $lockName = 'javcms_avdb_upgrade_'.substr(hash('sha256', (string)config('database.database').'|'.$prefix), 0, 32);
        $got = Db::query('SELECT GET_LOCK(?, 10) AS l', [$lockName]);
        if (!isset($got[0]['l']) || (string)$got[0]['l'] !== '1') { throw new \RuntimeException('The importer tables are being upgraded by another request. Try again in a minute.'); }
        $log = [];
        try {
            foreach (self::upgradeGroups(self::upgradeTodo($prefix)) as $group) {
                $started = microtime(true);
                $algorithm = self::upgradeRun($group['sql'], $group['alter']);
                if ($algorithm === 'already' && count($group['parts']) > 1) {
                    // the combined ALTER met a part that exists already: add the parts one by one
                    $algorithm = '';
                    foreach ($group['parts'] as $part) { $one = self::upgradeRun($part['sql'], true); $algorithm .= ($algorithm === '' ? '' : ',') . $one; }
                }
                $log[] = ['what' => $group['what'], 'algorithm' => $algorithm, 'statements' => count($group['parts']), 'seconds' => round(microtime(true) - $started, 3)];
            }
        } finally {
            try { Db::query('SELECT RELEASE_LOCK(?) AS r', [$lockName]); } catch (\Throwable $e) { /* connection gone */ }
            // ThinkPHP keeps each table's column list per process (think\db\Query::$info) and drops unknown columns on
            // insert/update; ready() already read the old lists, so forget them or this request would not write the
            // new columns.
            try { $info = new \ReflectionProperty(\think\db\Query::class, 'info'); if (PHP_VERSION_ID < 80100) { $info->setAccessible(true); } $info->setValue(null, []); } catch (\Throwable $e) { /* other framework build */ }
        }
        self::$upgradeDone = true;
        return $log;
    }
    /**
     * Pending ALTER items of one table -> one statement: "ALTER TABLE `t` ADD COLUMN a ..., ADD COLUMN b ..., ADD KEY k".
     * CREATE TABLE items stay single. Returns [['what','sql','alter'=>bool,'parts'=>items]] in file order of first use.
     */
    public static function upgradeGroups(array $todo)
    {
        $groups = []; $at = [];
        foreach ($todo as $item) {
            if (!$item['alter'] || !preg_match('/^ALTER TABLE `(\w+)` (ADD (?:COLUMN|KEY) .*)$/s', $item['sql'], $m)) {
                $groups[] = ['what' => $item['what'], 'sql' => $item['sql'], 'alter' => $item['alter'], 'parts' => [$item]];
                continue;
            }
            $key = strtolower($m[1]);
            if (!isset($at[$key])) { $at[$key] = count($groups); $groups[] = ['table' => $m[1], 'clauses' => [], 'what' => [], 'alter' => true, 'parts' => []]; }
            $g = &$groups[$at[$key]];
            $g['clauses'][] = $m[2]; $g['what'][] = $item['what']; $g['parts'][] = $item;
            unset($g);
        }
        foreach ($groups as $i => $g) {
            if (isset($g['clauses'])) {
                $groups[$i] = ['what' => implode(' + ', $g['what']), 'sql' => 'ALTER TABLE `' . $g['table'] . '` ' . implode(', ', $g['clauses']), 'alter' => true, 'parts' => $g['parts']];
            }
        }
        return $groups;
    }
    /** One upgrade statement with the algorithm ladder; returns INSTANT | INPLACE | default | create | already. */
    private static function upgradeRun($sql, $alter)
    {
        $variants = $alter ? ['INSTANT' => $sql.', ALGORITHM=INSTANT', 'INPLACE' => $sql.', ALGORITHM=INPLACE, LOCK=NONE', 'default' => $sql] : ['create' => $sql];
        $names = array_keys($variants);
        foreach ($names as $i => $name) {
            try { Db::execute($variants[$name]); return $name; }
            catch (\Throwable $e) {
                if (preg_match('/\b(1060|1061|1050)\b|Duplicate (column|key) name|already exists/i', $e->getMessage())) { return 'already'; }
                if ($i === count($names) - 1) { throw $e; }
            }
        }
        return '';
    }
    /** Pending statements of avdb-import-upgrade.sql: [['what','sql','alter'=>bool]], read from information_schema. */
    private static function upgradeTodo($prefix)
    {
        $sql = (string)file_get_contents(APP_PATH.'install/sql/avdb-import-upgrade.sql');
        $statements = []; $tables = [];
        foreach (explode(';', str_replace('javcms_', $prefix, $sql)) as $statement) {
            $statement = trim($statement); if ($statement === '') { continue; }
            if (preg_match('/^ALTER TABLE `(\w+)` ADD (COLUMN|KEY) `(\w+)`/', $statement, $m)) { $statements[] = ['table' => $m[1], 'kind' => $m[2], 'name' => $m[3], 'sql' => $statement]; $tables[strtolower($m[1])] = true; }
            elseif (preg_match('/^CREATE TABLE IF NOT EXISTS `(\w+)`/', $statement, $m)) { $statements[] = ['table' => $m[1], 'kind' => 'TABLE', 'name' => $m[1], 'sql' => $statement]; $tables[strtolower($m[1])] = true; }
            else { throw new \RuntimeException('Unsupported statement in avdb-import-upgrade.sql.'); }
        }
        $names = array_keys($tables); $in = implode(',', array_fill(0, count($names), '?'));
        $have = [];
        foreach (Db::query('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('.$in.')', $names) as $r) { $have['TABLE|'.strtolower($r['t']).'|'.strtolower($r['t'])] = true; }
        foreach (Db::query('SELECT table_name AS t, column_name AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name IN ('.$in.')', $names) as $r) { $have['COLUMN|'.strtolower($r['t']).'|'.strtolower($r['c'])] = true; }
        foreach (Db::query('SELECT DISTINCT table_name AS t, index_name AS k FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name IN ('.$in.')', $names) as $r) { $have['KEY|'.strtolower($r['t']).'|'.strtolower($r['k'])] = true; }
        $todo = [];
        foreach ($statements as $st) {
            if (isset($have[$st['kind'].'|'.strtolower($st['table']).'|'.strtolower($st['name'])])) { continue; }
            $todo[] = ['what' => $st['kind'].' '.$st['table'].($st['kind'] === 'TABLE' ? '' : '.'.$st['name']), 'sql' => $st['sql'], 'alter' => $st['kind'] !== 'TABLE'];
        }
        return $todo;
    }

    // ------------------------------------------------------------------ providers, states, contract calls

    /** Job state for a provider: providers other than 'avdb' use x_running / x_paused (D-I14, 1.1.0 never runs them). */
    public static function stateFor($state, $provider)
    {
        return ((string)$provider !== self::PROVIDER && in_array($state, ['running', 'paused'], true)) ? 'x_'.$state : $state;
    }
    /** State shown to the admin: x_running -> running, x_paused -> paused. */
    public static function publicState($state)
    {
        $state = (string)$state; return strpos($state, 'x_') === 0 ? substr($state, 2) : $state;
    }
    private static function jobProvider($job)
    {
        $p = is_array($job) ? (string)($job['provider'] ?? '') : (string)$job;
        return ImportProviders::isKey($p) ? $p : self::PROVIDER;
    }
    /** Provider instance of $key with its pacer (one politeness clock per provider, D-I6). */
    private function prov($key, $mode = 'bulk')
    {
        if (!isset($this->providers[$key])) {
            $this->providers[$key] = $key === self::PROVIDER ? new AvdbProvider($this->client) : ImportProviders::make($key);
        }
        $p = $this->providers[$key];
        if (method_exists($p, 'pacer') && method_exists($p, 'withPacer') && $p->pacer() === null) {
            $p->withPacer($this->pacerFactory ? call_user_func($this->pacerFactory, $key, $mode) : new ProviderPacer($key, $mode));
        }
        return $p;
    }
    private function caps($key) { return ImportProviders::get($key)->capabilities(); }
    /** Number for FtIndex (C1 takes an int job id; S stores the vod range, the id is informational). */
    public static function jobNumber($jobId) { return (int)hexdec(substr(preg_replace('/[^a-f0-9]/', '', (string)$jobId).'0', 0, 7)); }
    /** C1/C3/C4/C5/C9 calls never break an import: failures are logged. */
    private static function contract($class, $method, array $args = [])
    {
        $fq = $class === 'Vod' ? Vod::class : 'app\\common\\util\\'.$class;
        try {
            if (!class_exists($fq) || !method_exists($fq, $method)) { self::trace($class.'::'.$method.' [absent]'); return null; }
            $r = call_user_func_array([$fq, $method], $args);
            $shown = []; foreach ($args as $a) { $shown[] = is_array($a) ? 'ids:'.count($a) : (is_bool($a) ? ($a ? 'true' : 'false') : (string)$a); }
            self::trace($class.'::'.$method.'('.implode(',', $shown).')');
            return $r;
        } catch (\Throwable $e) {
            if (StorageGuard::isStorageError($e)) { StorageGuard::trip($e); }
            error_log('[AVDB] '.$class.'::'.$method.': '.get_class($e).': '.$e->getMessage());
            return null;
        }
    }

    /**
     * C12: pause every job and every schedule plan of every provider (tools/upgrade.php --prepare-rollback,
     * tools/import.php --pause --all). Returns jobs + plans paused; idempotent (second call 0).
     */
    public static function pauseAll(string $why): int
    {
        $why = mb_substr(trim($why) !== '' ? trim($why) : ImportProviders::text('paused_maintenance','Paused for maintenance.'), 0, 255, 'UTF-8');
        $n = 0;
        try { Db::name('avdb_import_job')->limit(1)->field('job_id')->select(); } catch (\Throwable $e) { return 0; }
        foreach (Db::name('avdb_import_job')->where('state', 'in', self::RUN_STATES)->field('job_id,state,options')->select() as $job) {
            $provider = self::PROVIDER;
            try { $row = Db::name('avdb_import_job')->where('job_id', $job['job_id'])->field('provider')->find(); $provider = self::jobProvider($row ?: []); } catch (\Throwable $e) { /* pre-upgrade schema */ }
            $n += (int)Db::name('avdb_import_job')->where(['job_id' => $job['job_id'], 'state' => $job['state']])->update(['state' => self::stateFor('paused', $provider), 'error' => $why, 'updated_at' => time()]);
        }
        try { Db::name('avdb_import_schedule')->limit(1)->field('client_id')->select(); } catch (\Throwable $e) { return $n; }
        foreach (Db::name('avdb_import_schedule')->select() as $row) {
            $options = json_decode((string)$row['options'], true); $options = is_array($options) ? $options : [];
            $plansPaused = 0;
            if (isset($options['plans']) && is_array($options['plans'])) {
                foreach ($options['plans'] as $i => $plan) { if (!empty($plan['enabled']) || !array_key_exists('enabled', (array)$plan)) { $options['plans'][$i]['enabled'] = 0; $plansPaused++; } }
            } elseif ((int)$row['enabled'] === 1) { $plansPaused = 1; }
            $changes = [];
            if ((int)$row['enabled'] === 1 || in_array($row['state'], ['idle', 'running'], true)) { $changes += ['enabled' => 0, 'state' => 'paused', 'epoch' => (int)$row['epoch'] + 1, 'lease_token' => '', 'lease_until' => 0, 'error' => $why]; }
            if (isset($options['plans']) && $plansPaused) { $changes['options'] = json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
            if ($changes) { $changes['updated_at'] = time(); Db::name('avdb_import_schedule')->where('client_id', $row['client_id'])->update($changes); $n += $plansPaused; }
        }
        // P-O1: paused for maintenance or a rollback to 1.1.0: the filter FULLTEXT keys come back now
        self::ftKeysRestore(PHP_SAPI === 'cli' ? 0 : self::FT_WEB_BUDGET, true);
        return $n;
    }
    /** Storage stop (D-I6 / D-I15): every running job and every schedule pause at once, without retry. */
    private static function pauseForStorage($message)
    {
        $message = mb_substr($message !== '' ? $message : 'Database or disk is full.', 0, 255, 'UTF-8');
        self::trace('storage_pause');
        try { Db::name('avdb_import_schedule')->where('enabled', 1)->update(['enabled' => 0, 'state' => 'paused', 'error' => $message, 'lease_token' => '', 'lease_until' => 0, 'updated_at' => time()]); } catch (\Throwable $e) { /* no schedule table */ }
    }

    public function capabilities($includeProvider = true)
    {
        $players=[]; foreach ((array)config('vodplayer') as $key=>$player) { if (!empty($player['status'])) { $players[]=['id'=>$key,'name'=>$player['show'] ?? $key]; } }
        $provider=[]; $providerError=''; if ($includeProvider) { try { $catalog=$this->client->fetch(['ac'=>'list','pagesize'=>1],'en'); $provider=$catalog['class'] ?? []; } catch (\Throwable $e) { $providerError=$e->getMessage(); } }
        return ['provider_error'=>$providerError,'ready'=>$this->ready(),'local_categories'=>Db::name('type')->where('type_mid',1)->field('type_id,type_name,type_pid,type_status')->select(),'players'=>$players,'languages'=>AvdbClient::languages(),'provider_categories'=>$provider,'default_player'=>in_array('ngm3u8',array_column($players,'id'),true)?'ngm3u8':($players[0]['id'] ?? '')];
    }
    private static function codes(array $row)
    {
        $codes=[]; foreach (['vod_sub','vod_remarks'] as $key) { if (isset($row[$key]) && is_scalar($row[$key]) && trim((string)$row[$key])!=='') { $codes[]=self::text($row[$key],255); } } return array_values(array_unique($codes));
    }
    /**
     * 2.5: codes used to find the same movie across providers. Providers with jav_codes: vod_sub / vod_remarks (as
     * today). Other providers: only a strict JAV code at the start of the title (^[A-Z]{2,6}-\d{2,5}\b); their numeric
     * ids are never used for matching.
     */
    public static function dedupeCodes(array $row, array $caps)
    {
        if (!empty($caps['jav_codes'])) { return self::codes($row); }
        $title = is_scalar($row['vod_name'] ?? null) ? html_entity_decode((string)$row['vod_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
        return preg_match('/^([A-Z]{2,6}-\d{2,5})\b/', trim($title), $m) ? [$m[1]] : [];
    }
    private static function matches(array $candidate,array $codes)
    {
        foreach (['vod_sub','vod_remarks'] as $key) { if (!empty($candidate[$key]) && in_array((string)$candidate[$key],$codes,true)) { return true; } } return false;
    }
    public function preview(array $filters)
    {
        $filters=$this->client->normalizeImportFilters($filters);
        $r=($filters['pagesize']===1000 || (isset($filters['tag']) && $filters['tag']!=='')) ? $this->client->fetchImport($filters,'en') : $this->client->fetch($filters,'en'); $rows=$r['list'] ?? []; $maps=[]; $local=[]; $codes=[];
        foreach ($rows as $row) { $codes=array_merge($codes,self::codes($row)); }
        if ($rows && $this->ready()) { foreach (Db::name('avdb_import_map')->where(['provider'=>self::PROVIDER,'remote_id'=>['in',array_column($rows,'vod_id')]])->select() as $map) { $maps[(int)$map['remote_id']]=(int)$map['vod_id']; } }
        $candidates=$codes ? Db::name('vod')->where('vod_sub|vod_remarks','in',array_values(array_unique($codes)))->field('vod_id,vod_name,vod_sub,vod_remarks,type_id,vod_lock,vod_recycle_time')->select() : [];
        $localIds=array_values(array_filter($maps)); if ($localIds) { foreach (Db::name('vod')->where('vod_id','in',$localIds)->field('vod_id,vod_name,vod_sub,type_id,vod_lock,vod_recycle_time')->select() as $vod) { $local[(int)$vod['vod_id']]=$vod; } }
        foreach ($rows as &$row) {
            $mapped=$maps[(int)$row['vod_id']] ?? 0; $row['existing']=$mapped ? ($local[$mapped] ?? ['vod_id'=>$mapped]) : null; $row['existing_candidate']=null;
            if (!$mapped) { $matches=array_values(array_filter($candidates,function($candidate) use($row) { return self::matches($candidate,self::codes($row)); })); if (count($matches)===1) { $row['existing_candidate']=$matches[0]+['ambiguous'=>false]; } elseif (count($matches)>1) { $row['existing_candidate']=['ambiguous'=>true,'count'=>count($matches)]; } }
        } unset($row);
        return ['items'=>$rows,'page'=>(int)($r['page'] ?? 1),'pagesize'=>(int)($r['limit'] ?? 20),'total'=>(int)($r['total'] ?? 0),'pagecount'=>(int)($r['pagecount'] ?? 0),'total_estimated'=>true,'has_more'=>count($rows)>=(int)($r['limit'] ?? 20)];
    }

    // ------------------------------------------------------------------ options

    /**
     * Job options. `provider` (registry key, default 'avdb') and `mode`:
     *  - selected | pages | all: unchanged AVDB modes (pages / all need stable_cursor 'export', the AVDB filter API);
     *    `selected` works for every provider (ids_max per request);
     *  - recent (max_items = scope size), category (categories = remote type ids; max_items 0 = all), windows (id
     *    windows over the whole catalog, stable_cursor 'id_windows'; max_items 0 = all), snapshot (the whole catalog
     *    from the xvidexport snapshot, falling back to the keyset export API, then to id windows; XVID-NOLIMIT-BRIEF A4),
     *    incremental (hours 3|24|168, schedule / tick), feed (the xvidexport changes + removed feeds from the stored
     *    cursors, schedule / tick), presence (sweep or recheck of local map ids, schedule / CLI).
     * Common additions: runner web|cli, placement new|upload (D19), override_tombstones (selected only, D-I9),
     * preset grouped|mirror, skip_types, include_gay_trans (D5), xfilters {skip_short, skip_unknown, skip_sd, langs},
     * image_hosts_extra.
     */
    public static function normalizeOptions(array $o)
    {
        $provider=$o['provider'] ?? self::PROVIDER;
        if (!is_string($provider) || !ImportProviders::isKey($provider)) { throw new \RuntimeException(ImportProviders::text('provider_unknown','Unknown import provider.')); }
        $caps=ImportProviders::get($provider)->capabilities();
        $mode=$o['mode'] ?? 'selected';
        $legacy=in_array($mode,['selected','pages','all'],true) && ($caps['stable_cursor'] ?? '')==='export';
        if (!$legacy && !in_array($mode,['selected','recent','category','windows','snapshot','incremental','feed','presence'],true)) { throw new \RuntimeException('Choose selected videos, a page range or all matching videos.'); }
        if (in_array($mode,['windows','snapshot','feed'],true) && ($caps['stable_cursor'] ?? '')!=='id_windows') { throw new \RuntimeException(ImportProviders::text('no_windows','This provider has no id windows; import all matching videos instead.')); }
        $out=$legacy ? self::normalizeLegacy($o) : self::normalizeScope($o,$mode,$caps);
        $out['provider']=$provider; $out['type']='import';
        $runner=$o['runner'] ?? 'web'; if (!in_array($runner,['web','cli'],true)) { throw new \RuntimeException('Invalid import runner.'); } $out['runner']=$runner;
        $placement=$o['placement'] ?? null;
        if ($placement===null) { $cfg=config('maccms.import'); $placement=is_array($cfg) && in_array($cfg['placement'] ?? '',['new','upload'],true) ? $cfg['placement'] : 'new'; }
        if (!in_array($placement,['new','upload'],true)) { throw new \RuntimeException('Choose "as new arrivals" or "by upload date".'); } $out['placement']=$placement;
        $override=$o['override_tombstones'] ?? 0; if (!in_array($override,[0,1,'0','1',false,true],true)) { throw new \RuntimeException('Invalid tombstone override.'); }
        $out['override_tombstones']=$mode==='selected' && (int)$override ? 1 : 0;
        $hosts=[]; foreach ((array)($o['image_hosts_extra'] ?? []) as $host) { if (!is_string($host) || !preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/D',strtolower($host))) { throw new \RuntimeException('Invalid image host.'); } $hosts[strtolower($host)]=strtolower($host); }
        $out['image_hosts_extra']=array_values($hosts);
        return $out;
    }
    /** The 1.1.0 option rules (AVDB selected / pages / all), unchanged. */
    private static function normalizeLegacy(array $o)
    {
        $mode=$o['mode'] ?? 'selected'; if (!in_array($mode,['selected','pages','all'],true)) { throw new \RuntimeException('Choose selected videos, a page range or all matching videos.'); }
        $ids=[]; foreach ((array)($o['ids'] ?? []) as $id) { if (!is_scalar($id) || !preg_match('/^[1-9][0-9]*$/D',(string)$id) || (float)$id>2147483647) { throw new \RuntimeException('Invalid video ID.'); } $ids[(int)$id]=(int)$id; }
        if ($mode==='selected' && !$ids) { throw new \RuntimeException('Select at least one video.'); }
        $rawMax=$o['max_items'] ?? ($mode==='all'?0:1000); if ((!is_int($rawMax) && !is_string($rawMax)) || !preg_match($mode==='all'?'/^(?:0|[1-9][0-9]*)$/D':'/^[1-9][0-9]*$/D',(string)$rawMax) || (float)$rawMax>2147483647) { throw new \RuntimeException('Item limit must be a positive whole number up to 2147483647 (the supported integer range).'); } $max=(int)$rawMax; $start=(int)($o['start_page'] ?? 1); $end=(int)($o['end_page'] ?? $start); if ($mode==='all') { $start=1; $end=1; }
        if ($start<1 || $start>1000000 || $end>1000000 || $end<$start || count($ids)>10000) { throw new \RuntimeException('Invalid import bounds.'); }
        $locales=array_values(array_unique(array_merge(['en'],(array)($o['locales'] ?? array_keys(AvdbClient::languages())))));
        $allowed=['en','vi','th','id','ko','zh','ja','fr','de','es','pt','ru','ar','tr','hi','ms','tl'];
        foreach ($locales as $locale) { if (!in_array($locale,$allowed,true)) { throw new \RuntimeException('Unsupported language.'); } }
        $common=self::normalizeCommon($o,$mode,$ids);
        return ['mode'=>$mode,'ids'=>array_values($ids),'filters'=>(new AvdbClient())->normalizeImportFilters((array)($o['filters'] ?? [])),'start_page'=>$start,'end_page'=>$end,'max_items'=>$max,'locales'=>$locales]+$common;
    }
    /** Shared option rules (category / player maps, links, images, policies). */
    private static function normalizeCommon(array $o,$mode,array $ids)
    {
        $groups=array_values(array_unique((array)($o['update_fields'] ?? [])));
        foreach ($groups as $group) { if (!in_array($group,['text','metadata','images','playback','translations'],true)) { throw new \RuntimeException('Invalid update group.'); } }
        foreach ((array)($o['category_map'] ?? []) as $remote=>$local) { if (!is_scalar($local) || !preg_match('/^[1-9][0-9]*$/D',(string)$local) || !preg_match('/^[1-9][0-9]*$/D',(string)$remote)) { throw new \RuntimeException('Invalid category mapping.'); } }
        foreach ((array)($o['player_map'] ?? []) as $source=>$player) { if (!is_string($player) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$player) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D',(string)$source)) { throw new \RuntimeException('Invalid player mapping.'); } }
        $links=[]; foreach ((array)($o['existing_links'] ?? []) as $remote=>$local) { if (!is_scalar($local) || !preg_match('/^[1-9][0-9]*$/D',(string)$local) || !preg_match('/^[1-9][0-9]*$/D',(string)$remote) || (float)$remote>2147483647 || (float)$local>2147483647) { throw new \RuntimeException('Invalid existing-video link.'); } if ($mode==='selected' && !isset($ids[(int)$remote])) { throw new \RuntimeException('Existing-video links must refer to selected IDs.'); } $links[(int)$remote]=(int)$local; }
        $download=$o['download_images'] ?? 0; if (!in_array($download,[0,1,'0','1',false,true],true)) { throw new \RuntimeException('Choose whether to download cover images using 0 or 1.'); }
        $create=array_key_exists('create_categories',$o) ? $o['create_categories'] : 1; if (!in_array($create,[0,1,'0','1',false,true],true)) { throw new \RuntimeException('Choose whether to create categories using 0 or 1.'); }
        $defaultPlayer=array_key_exists('default_player',$o)?$o['default_player']:null; if ($defaultPlayer!==null && (!is_string($defaultPlayer) || ($defaultPlayer!=='' && !preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$defaultPlayer)))) { throw new \RuntimeException('Invalid default player.'); }
        $existing=$o['existing'] ?? 'smart'; if (!in_array($existing,['skip','update','smart'],true)) { throw new \RuntimeException('Invalid existing-video policy.'); }
        return ['category_map'=>(array)($o['category_map'] ?? []),'player_map'=>(array)($o['player_map'] ?? []),'default_player'=>$defaultPlayer,'download_images'=>(int)$download,'create_categories'=>(int)$create,'existing'=>$existing,'existing_links'=>$links,'update_fields'=>$groups,'publish'=>!empty($o['publish']) ? 1 : 0,'drop_indexes'=>self::flag($o['drop_indexes'] ?? 0,'Choose whether to drop the filter indexes during the import using 0 or 1.')];
    }
    /** 0/1 option ('0', '1', 0, 1, false, true). */
    private static function flag($v,$message) { if (!in_array($v,[0,1,'0','1',false,true],true)) { throw new \RuntimeException($message); } return (int)$v; }
    /** Options of the 1.2.0 scopes (recent, category, windows, incremental, presence; selected for non-export providers). */
    private static function normalizeScope(array $o,$mode,array $caps)
    {
        $ids=[];
        if ($mode==='selected') {
            foreach ((array)($o['ids'] ?? []) as $id) { if (!is_scalar($id) || !preg_match('/^[1-9][0-9]*$/D',(string)$id) || (float)$id>2147483647) { throw new \RuntimeException('Invalid video ID.'); } $ids[(int)$id]=(int)$id; }
            if (!$ids) { throw new \RuntimeException('Select at least one video.'); }
            if (count($ids)>10000) { throw new \RuntimeException('Invalid import bounds.'); }
        }
        $rawMax=$o['max_items'] ?? ($mode==='recent'?50000:0);
        if ((!is_int($rawMax) && !is_string($rawMax)) || !preg_match('/^(?:0|[1-9][0-9]*)$/D',(string)$rawMax) || (float)$rawMax>2147483647 || ($mode==='recent' && (int)$rawMax<1)) { throw new \RuntimeException('Item limit must be a positive whole number up to 2147483647 (the supported integer range).'); }
        $categories=[];
        if ($mode==='category') {
            foreach ((array)($o['categories'] ?? []) as $t) { if (!is_scalar($t) || !preg_match('/^[1-9][0-9]{0,5}$/D',(string)$t)) { throw new \RuntimeException('Invalid provider category.'); } $categories[(int)$t]=(int)$t; }
            if (!$categories || count($categories)>100) { throw new \RuntimeException(ImportProviders::text('choose_categories','Choose 1 to 100 provider categories.')); }
        }
        $hours=(int)($o['hours'] ?? 24); if ($mode==='incremental' && !in_array($hours,XvidClient::HOURS,true)) { throw new \RuntimeException('Use a canonical sync window of 3, 24 or 168 hours.'); }
        $parts=(int)($o['parts'] ?? 1); $part=(int)($o['part'] ?? 0); if (!in_array($parts,[1,4],true) || $part<0 || $part>=$parts) { throw new \RuntimeException('Invalid presence part.'); }
        $preset=$o['preset'] ?? 'grouped'; if (!in_array($preset,['grouped','mirror'],true)) { throw new \RuntimeException('Choose the Grouped or the Mirror category preset.'); }
        $skip=[]; foreach ((array)($o['skip_types'] ?? []) as $t) { if (!is_scalar($t) || !preg_match('/^[1-9][0-9]{0,5}$/D',(string)$t)) { throw new \RuntimeException('Invalid provider category.'); } $skip[(int)$t]=(int)$t; }
        // O1 (XVID-NOLIMIT-BRIEF): the whole catalog imports every row unless the admin ticks a filter; smaller scopes keep D5's defaults
        $full=in_array($mode,['windows','snapshot'],true);
        $xf=(array)($o['xfilters'] ?? []); $x=[];
        foreach (['skip_short'=>$full?0:1,'skip_unknown'=>$full?0:1,'skip_sd'=>0] as $k=>$default) { $v=$xf[$k] ?? $default; if (!in_array($v,[0,1,'0','1',false,true],true)) { throw new \RuntimeException('Invalid import filter.'); } $x[$k]=(int)$v; }
        $langs=[]; foreach ((array)($xf['langs'] ?? []) as $l) { if (!is_string($l) || !preg_match('/^[a-z]{2}$/D',$l)) { throw new \RuntimeException('Invalid source language.'); } $langs[$l]=$l; } if (count($langs)>30) { throw new \RuntimeException('Invalid source language.'); } $x['langs']=array_values($langs);
        // O2: Gay / Shemale / Bi Sexual ticked for the whole catalog (one click unticks them); D5 default off for smaller scopes
        $gt=$o['include_gay_trans'] ?? ($full ? 1 : 0); if (!in_array($gt,[0,1,'0','1',false,true],true)) { throw new \RuntimeException('Invalid category choice.'); }
        $recheck=$o['recheck'] ?? 0; if (!in_array($recheck,[0,1,'0','1',false,true],true)) { throw new \RuntimeException('Invalid presence option.'); }
        $via=$o['via'] ?? ''; if (!in_array($via,['','snapshot','windows'],true)) { throw new \RuntimeException('Choose --via=snapshot or --via=windows.'); }
        $locales=['en'];
        if (!empty($caps['translations'])) { $locales=array_values(array_unique(array_merge(['en'],array_values(array_intersect((array)($o['locales'] ?? $caps['translations']),$caps['translations']))))); }
        $common=self::normalizeCommon($o,$mode,$ids);
        return ['mode'=>$mode,'ids'=>array_values($ids),'filters'=>[],'start_page'=>1,'end_page'=>1,'max_items'=>(int)$rawMax,'locales'=>$locales,'categories'=>array_values($categories),'hours'=>$hours,'parts'=>$parts,'part'=>$part,'recheck'=>(int)$recheck,'preset'=>$preset,'skip_types'=>array_values($skip),'include_gay_trans'=>(int)$gt,'xfilters'=>$x,'via'=>$via]+$common;
    }
    private static function providerCategories($classes)
    {
        if (!is_array($classes) || !$classes || count($classes)>200) { throw new \RuntimeException('AVDB returned an invalid category list.'); }
        $out=[];
        foreach ($classes as $row) {
            $id=is_array($row) ? ($row['type_id'] ?? null) : null; $name=is_array($row) ? ($row['type_name'] ?? null) : null;
            if ((!is_int($id)&&!is_string($id)) || !preg_match('/^[1-9][0-9]*$/D',(string)$id) || (float)$id>2147483647 || !is_string($name) || !preg_match('//u',$name) || preg_match('/[\x00-\x1f\x7f<>]/',$name) || trim($name)==='' || mb_strlen(trim($name),'UTF-8')>60 || isset($out[(int)$id])) { throw new \RuntimeException('AVDB returned an invalid category definition.'); }
            $out[(int)$id]=trim($name);
        }
        return $out;
    }
    private function grantCreatedCategoryAccess(array $typeIds)
    {
        if (!$typeIds) { return; }
        // The setup transaction owns these newly created public categories.
        // Lock groups consistently; retain every existing category policy.
        $groups=Db::name('group')->where('group_status',1)->order('group_id asc')->lock(true)->select();
        foreach ($groups as $group) {
            $raw=(string)$group['group_popedom']; $permissions=$raw==='' ? [] : json_decode($raw,true);
            if (!is_array($permissions)) { throw new \RuntimeException('Category group permissions are invalid.'); }
            $types=(string)$group['group_type']; $changed=false;
            foreach ($typeIds as $typeId) {
                if (array_key_exists((string)$typeId,$permissions)) { continue; }
                // Match native fresh-install public category permissions.
                $permissions[(string)$typeId]=['1'=>'1','2'=>'2','3'=>'3','4'=>'4','5'=>'5'];
                if (strpos(','.$types,','.$typeId.',')===false) { $types=rtrim($types,',').','.$typeId.','; }
                $changed=true;
            }
            if ($changed) {
                Db::name('group')->where('group_id',(int)$group['group_id'])->update(['group_type'=>$types,'group_popedom'=>$this->json($permissions)]);
                $this->categoryAccessDirty=true;
            }
        }
    }
    private function categorySetup(array $o,array $classes)
    {
        // r5 repair (beginner r5 P1-2): every video category, the visible ones first. A category the admin hid is reused
        // (it stays hidden) instead of creating a second, visible one with the same name.
        $locals=Db::name('type')->where(['type_mid'=>1])->order('type_status desc, type_id asc')->select(); $names=[]; $enabled=[];
        foreach ($locals as $type) { $k=mb_strtolower(trim($type['type_name']),'UTF-8'); $names[$k][]=$type; if ((int)$type['type_status']===1) { $enabled[$k][]=$type; } }
        // Resolve every ambiguity before any insertion, including later source categories.
        foreach ($classes as $remote=>$name) { if (!isset($o['category_map'][$remote]) && count($enabled[mb_strtolower($name,'UTF-8')] ?? [])>1) { throw new \RuntimeException('More than one enabled video category matches "'.$name.'". Choose its destination explicitly.'); } }
        $o['created_category_ids']=[]; $o['category_setup']=[];
        foreach ($classes as $remote=>$name) {
            $matches=$names[mb_strtolower($name,'UTF-8')] ?? [];
            if (isset($o['category_map'][$remote])) { $local=(int)$o['category_map'][$remote]; $action='manual'; }
            elseif ($matches) {
                $local=(int)$matches[0]['type_id']; $action=(isset($matches[0]['type_status']) && (int)$matches[0]['type_status']!==1) ? 'reused_hidden' : 'reused';
                // HO-E-8: a category the installer seeded off (hidden and still empty, e.g. JAV rows on an "xvidapi only"
                // install) is turned on by the first import that maps into it; a hidden category with videos stays hidden.
                if ($action==='reused_hidden' && !Db::name('vod')->where('type_id',$local)->field('vod_id')->find()) { Db::name('type')->where('type_id',$local)->update(['type_status'=>1]); $action='enabled_empty'; $o['enabled_parent']=$local; }
            }
            else {
                // Readable, unique category address from the name ("English subtitle" -> english-subtitle).
                // A taken address gets -c<source id> (then x2, x3...): never a trailing -<digits>, which /category/<slug>-<page>/ reads as a page.
                $base=self::categorySlug($name,(int)$remote); $slug=$base; $suffix=0;
                while (Db::name('type')->where('type_en',$slug)->find()) { if (++$suffix>200) { throw new \RuntimeException('Could not choose a unique category address.'); } $slug=$base.'-c'.(int)$remote.($suffix>1 ? 'x'.$suffix : ''); }
                $local=(int)Db::name('type')->insertGetId(['type_name'=>$name,'type_en'=>$slug,'type_mid'=>1,'type_pid'=>0,'type_status'=>1,'type_sort'=>0,'type_tpl'=>'type.html','type_tpl_list'=>'show.html','type_tpl_detail'=>'detail.html','type_tpl_play'=>'play.html','type_tpl_down'=>'down.html','type_extend'=>'']);
                // C6 (HO-T-3): open the new category to the user groups.
                self::contract('TypeAccess','grantNew',[(int)$local,0]);
                $names[mb_strtolower($name,'UTF-8')][]=['type_id'=>$local,'type_name'=>$name,'type_status'=>1];
                $o['created_category_ids'][]=(int)$local; $action='created';
            }
            $o['category_map'][$remote]=$local;
            $o['category_setup'][]=['remote_id'=>(int)$remote,'name'=>$name,'local_id'=>$local,'action'=>$action];
        }
        $this->grantCreatedCategoryAccess($o['created_category_ids']);
        return $o;
    }
    /** Category name key for matching: lower case, '_' as space, single spaces. */
    private static function nameKey($name) { return mb_strtolower(trim((string)preg_replace('/\s+/u',' ',str_replace('_',' ',(string)$name))),'UTF-8'); }
    /**
     * 2.6 categories of a provider with a category parent (xvid): the parent is the type with slug `western` (created
     * when missing; enabled on the first import; while it was a hidden seed called "Western" it gets the D20 display
     * name from the capability, "International"). Children: preset Grouped (IMP 6.1, about 12) or Mirror (one per remote
     * category). Names are matched only among the parent's children and through avdb_provider_type, never across all
     * categories. "Xvidapi" / "Unknown" are never created: their rows go to the child "Other" (slug xv-other) in both
     * presets (XVID-NOLIMIT-BRIEF O1: the whole catalog keeps them; never a category named Unknown / Xvidapi). Unticked remote
     * categories (skip_types, D5 Gay / Shemale / Bi Sexual by default) are recorded with import=0 and their groups are
     * not created. Every created category goes through TypeAccess::grantNew (C6).
     */
    private function categorySetupProvider(array $o, ImportProvider $p, array $classes)
    {
        $provider=$p->key(); $caps=$p->capabilities(); $now=$this->now();
        $parent=Db::name('type')->where(['type_en'=>'western','type_mid'=>1])->lock(true)->find();
        $displayName=(string)($caps['category_parent'] ?: 'International');
        if (!$parent) {
            $pid=(int)Db::name('type')->insertGetId(['type_name'=>$displayName,'type_en'=>'western','type_mid'=>1,'type_pid'=>0,'type_status'=>1,'type_sort'=>0,'type_tpl'=>'type.html','type_tpl_list'=>'show.html','type_tpl_detail'=>'detail.html','type_tpl_play'=>'play.html','type_tpl_down'=>'down.html','type_extend'=>'']);
            self::contract('TypeAccess','grantNew',[$pid,0]); $this->grantCreatedCategoryAccess([$pid]); $o['created_category_ids'][]=$pid;
        } else {
            $pid=(int)$parent['type_id'];
            if ((int)$parent['type_pid']!==0) { throw new \RuntimeException(ImportProviders::text('parent_not_top','The category "western" must be a top-level category for this provider.')); }
            if ((int)$parent['type_status']!==1) {
                $set=['type_status'=>1]; if (self::nameKey($parent['type_name'])==='western' && $displayName!=='') { $set['type_name']=$displayName; }
                Db::name('type')->where('type_id',$pid)->update($set);
                self::contract('TypeAccess','grantNew',[$pid,0]); $this->grantCreatedCategoryAccess([$pid]); $o['enabled_parent']=$pid;
            }
        }
        $off=[]; foreach ((array)$o['skip_types'] as $t) { $off[(int)$t]=true; }
        foreach ($classes as $remote=>$name) {
            $k=self::nameKey($name);
            if (!$o['include_gay_trans'] && in_array($k,self::DEFAULT_OFF,true)) { $off[(int)$remote]=true; }
        }
        $o['skip_types']=array_values(array_map('intval',array_keys($off)));
        // remote -> target child name
        $target=[];
        foreach ($classes as $remote=>$name) {
            $k=self::nameKey($name);
            if (in_array($k,self::NEVER_CREATE,true)) { $target[$remote]='Other'; continue; }
            if ($o['preset']==='mirror') { $target[$remote]=trim((string)preg_replace('/\s+/u',' ',str_replace('_',' ',$name))); continue; }
            $group='Other';
            foreach (self::GROUPED as $g) { foreach ($g[1] as $member) { if (self::nameKey($member)===$k) { $group=$g[0]; break 2; } } }
            $target[$remote]=$group;
        }
        // children needed: targets of ticked remote categories (a group of only unticked categories is not created)
        $needed=[]; foreach ($target as $remote=>$child) { if ($child!==null && !isset($off[(int)$remote])) { $needed[self::nameKey($child)]=$child; } }
        // Grouped: children in the IMP 6.1 order (type_sort); Mirror: the provider's order.
        if ($o['preset']==='grouped') { $order=[]; foreach (self::GROUPED as $gi=>$g) { $order[self::nameKey($g[0])]=$gi; } uksort($needed,function ($a,$b) use ($order) { return ($order[$a] ?? 99) <=> ($order[$b] ?? 99); }); }
        $children=[]; foreach (Db::name('type')->where(['type_pid'=>$pid,'type_mid'=>1])->order('type_status desc, type_id asc')->select() as $type) { $kk=self::nameKey($type['type_name']); if (!isset($children[$kk])) { $children[$kk]=(int)$type['type_id']; } }
        $known=[]; foreach (Db::name('avdb_provider_type')->where('provider',$provider)->select() as $row) { if ((int)$row['local_type_id']>0) { $known[(int)$row['remote_type_id']]=(int)$row['local_type_id']; } }
        $existingTypes=[]; if ($known) { foreach (Db::name('type')->where('type_id','in',array_values($known))->field('type_id,type_pid')->select() as $t) { $existingTypes[(int)$t['type_id']]=(int)$t['type_pid']; } }
        $prefix=substr($provider,0,2).'-';
        $o['category_setup']=[]; $local=[]; $sort=0;
        foreach ($needed as $kk=>$child) {
            $sort++;
            if (isset($children[$kk])) { $local[$kk]=$children[$kk]; continue; }
            $base=$prefix.self::categorySlug($child,$sort); $slug=$base; $suffix=0;
            while (Db::name('type')->where('type_en',$slug)->find()) { if (++$suffix>200) { throw new \RuntimeException('Could not choose a unique category address.'); } $slug=$base.'-x'.$suffix; }
            $id=(int)Db::name('type')->insertGetId(['type_name'=>mb_substr($child,0,60,'UTF-8'),'type_en'=>$slug,'type_mid'=>1,'type_pid'=>$pid,'type_status'=>1,'type_sort'=>$sort,'type_tpl'=>'type.html','type_tpl_list'=>'show.html','type_tpl_detail'=>'detail.html','type_tpl_play'=>'play.html','type_tpl_down'=>'down.html','type_extend'=>'']);
            self::contract('TypeAccess','grantNew',[$id,$pid]);
            $this->grantCreatedCategoryAccess([$id]);
            $local[$kk]=$id; $children[$kk]=$id; $o['created_category_ids'][]=$id;
            $o['category_setup'][]=['remote_id'=>0,'name'=>$child,'local_id'=>$id,'action'=>'created'];
        }
        foreach ($classes as $remote=>$name) {
            $remote=(int)$remote;
            if (isset($o['category_map'][$remote])) { $to=(int)$o['category_map'][$remote]; $action='manual'; }
            elseif (isset($known[$remote]) && isset($existingTypes[$known[$remote]]) && ($existingTypes[$known[$remote]]===$pid || $known[$remote]===$pid)) { $to=$known[$remote]; $action='kept'; }
            elseif ($target[$remote]===null) { $to=$pid; $action='parent'; }
            else { $to=$local[self::nameKey($target[$remote])] ?? 0; $action=$to ? 'mapped' : 'unticked'; }
            if ($to && !isset($off[$remote])) { $o['category_map'][$remote]=$to; }
            $row=['provider'=>$provider,'remote_type_id'=>$remote,'local_type_id'=>$to,'remote_name'=>mb_substr((string)$name,0,64,'UTF-8'),'import'=>isset($off[$remote])?0:1,'updated_at'=>$now];
            $exists=Db::name('avdb_provider_type')->where(['provider'=>$provider,'remote_type_id'=>$remote])->find();
            if ($exists) { Db::name('avdb_provider_type')->where(['provider'=>$provider,'remote_type_id'=>$remote])->update($row); } else { Db::name('avdb_provider_type')->insert($row); }
            $o['category_setup'][]=['remote_id'=>$remote,'name'=>$name,'local_id'=>$to,'action'=>isset($off[$remote])?'unticked':$action];
        }
        $o['category_parent_id']=$pid;
        return $o;
    }
    /**
     * URL slug for a category created by the importer: lower-case ASCII words joined by hyphens,
     * at most 48 characters, never ending in -<digits>; names without Latin letters fall back to category<remote id>.
     */
    public static function categorySlug($name,$remote)
    {
        $text=mb_strtolower(trim((string)$name),'UTF-8');
        $fold=['a'=>'àáâãäåāăąǎạảấầẩẫậắằẳẵặ','e'=>'èéêëēĕėęěẹẻẽếềểễệ','i'=>'ìíîïĩīĭįǐỉịı','o'=>'òóôõöøōŏőơǒọỏốồổỗộớờởỡợ','u'=>'ùúûüũūŭůűųưǔụủứừửữự','y'=>'ýÿŷỳỵỷỹ','n'=>'ñńņň','c'=>'çćĉċč','d'=>'ďđð','g'=>'ĝğġģ','h'=>'ĥħ','j'=>'ĵ','k'=>'ķ','l'=>'ĺļľŀł','r'=>'ŕŗř','s'=>'śŝşšș','t'=>'ţťŧț','z'=>'źżž','ss'=>'ß','ae'=>'æ','oe'=>'œ','th'=>'þ'];
        $pairs=[];
        foreach ($fold as $ascii=>$chars) { foreach (preg_split('//u',$chars,-1,PREG_SPLIT_NO_EMPTY) as $char) { $pairs[$char]=$ascii; } }
        $text=strtr($text,$pairs);
        if (preg_match('/[^\x00-\x7F]/',$text) && function_exists('transliterator_transliterate')) { $latin=@transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()',$text); if (is_string($latin)) { $text=$latin; } }
        $slug=trim(preg_replace('/[^a-z0-9]+/','-',$text),'-');
        if (strlen($slug)>48) { $slug=rtrim(substr($slug,0,48),'-'); }
        // Drop every hyphen in the trailing digits-and-hyphens run, so the slug never ends in -<digits>,
        // which /category/<slug>-<page>/ reads as a page number. "Top 100" -> "top100";
        // "Season 1-2" -> "season12"; "Best of 2023-2024" -> "best-of20232024".
        $slug=preg_replace('/-(?=[0-9-]*\z)/','',$slug);
        if ($slug==='' || ctype_digit($slug)) { $slug='category'.(int)$remote; }
        return $slug;
    }
    private static function fullExportOptions(array $o)
    {
        if ($o['mode']!=='all' || (int)$o['max_items']!==0 || (int)($o['filters']['pagesize'] ?? 0)!==1000) { return false; }
        foreach ($o['filters'] as $key=>$value) {
            if (in_array($key,['ac','pg','pagesize'],true) || $value==='') { continue; }
            if ($key==='sort_direction' && $value==='desc') { continue; }
            return false;
        }
        return true;
    }

    public function startScheduled($owner,array $options,$client,$epoch)
    {
        $this->scheduleContext=['owner'=>(int)$owner,'client'=>(string)$client,'epoch'=>(int)$epoch];
        try { return $this->start($owner,$options); } finally { $this->scheduleContext=null; }
    }
    public function stepScheduled($owner,$id,$client,$epoch)
    {
        $job=$this->job($owner,$id); $o=$this->decode($job['options']);
        if (($o['_schedule_client_id']??'')!==$client || (int)($o['_schedule_epoch']??0)!==(int)$epoch) { throw new AvdbImportLeaseException('Automatic import settings changed.'); }
        $this->scheduleContext=['owner'=>(int)$owner,'client'=>$client,'epoch'=>(int)$epoch];
        try { return $this->step($owner,$id); } finally { $this->scheduleContext=null; }
    }
    private function scheduleFence()
    {
        if (!$this->scheduleContext) { return; }
        $c=$this->scheduleContext;
        $row=Db::name('avdb_import_schedule')->where('client_id',$c['client'])->lock(true)->find();
        if (!$row || (int)$row['admin_id']!==$c['owner'] || !(int)$row['enabled'] || (int)$row['epoch']!==$c['epoch'] || !in_array($row['state'],['idle','running'],true)) { throw new AvdbImportLeaseException('Automatic import was paused, disabled or changed.'); }
        $a=Db::name('admin')->where('admin_id',$c['owner'])->field('admin_id,admin_status,admin_auth')->find();
        if (!$a || (int)$a['admin_status']!==1) { throw new AvdbImportLeaseException('Automatic import permission is unavailable.'); }
        if ((int)$a['admin_id']!==1) {
            $auth=explode(',',strtolower((string)$a['admin_auth']));
            foreach (['avdbimport/index','avdbimport/start','avdbimport/step','avdbimport/schedulesave'] as $p) { if (!in_array($p,$auth,true)) { throw new AvdbImportLeaseException('Automatic import permission is unavailable.'); } }
        }
    }
    private function startFence($owner,$exclude='')
    {
        $rows=Db::name('avdb_import_job')->where(['state'=>['in',array_merge(self::RUN_STATES,self::PAUSE_STATES)]])->lock(true)->select();
        foreach ($rows as $job) {
            if ($job['job_id']===$exclude) { continue; }
            $o=$this->decode($job['options']); $scheduled=!empty($o['_schedule_client_id']);
            if ($scheduled) {
                $plan=Db::name('avdb_import_schedule')->where('client_id',$o['_schedule_client_id'])->lock(true)->find();
                if (!$plan || !(int)$plan['enabled'] || (int)$plan['epoch']!==(int)($o['_schedule_epoch']??0)) {
                    Db::name('avdb_import_job')->where('job_id',$job['job_id'])->update(['state'=>'cancelled','lock_token'=>'','lock_until'=>0,'error'=>'Automatic import settings changed.','updated_at'=>time()]);
                    continue;
                }
                throw new \RuntimeException('An automatic import is active. Pause or turn it off before starting another import.');
            }
            if ($this->scheduleContext) { throw new \RuntimeException('A manual import is active. Finish or stop it before automatic imports.'); }
        }
        $this->scheduleFence();
    }
    public function start($owner,array $options)
    {
        if ((int)$owner<1) { throw new \RuntimeException('Authentication required.'); }
        $name='avdb_start_'.substr(hash('sha256',(string)config('database.database').'|'.(string)config('database.prefix')),0,40);
        $r=Db::query('SELECT GET_LOCK(:lock_name,5) AS acquired',['lock_name'=>$name],true);
        if ((int)($r[0]['acquired']??0)!==1) { throw new \RuntimeException('Import setup is busy. Try again shortly.'); }
        try { $status=$this->createJob($owner,$options); }
        finally { Db::query('SELECT RELEASE_LOCK(:lock_name) AS released',['lock_name'=>$name],true); }
        // Continue any journal retention work an earlier call left (bounded, outside the setup lock).
        $this->trimHistoryQuietly();
        return $status;
    }
    /** Remote category names of a provider: avdb_provider_type cache (refreshed at most weekly), else one list call. */
    private function providerClassNames(ImportProvider $p)
    {
        $rows=[]; try { $rows=Db::name('avdb_provider_type')->where('provider',$p->key())->field('remote_type_id,remote_name,updated_at')->select(); } catch (\Throwable $e) { $rows=[]; }
        $fresh=$rows && min(array_map('intval',array_column($rows,'updated_at')))>$this->now()-7*86400;
        if ($fresh) { $out=[]; foreach ($rows as $r) { if ((string)$r['remote_name']!=='') { $out[(int)$r['remote_type_id']]=(string)$r['remote_name']; } } if ($out) { return $out; } }
        $out=$p->categories('en');
        if (!$out || count($out)>500) { throw new \RuntimeException(ImportProviders::text('bad_categories','The provider returned an invalid category list.')); }
        // Cache the names (a week): the capabilities panel and the next job start need no provider request.
        try {
            $now=$this->now(); $known=array_fill_keys(array_map('intval',array_column($rows,'remote_type_id')),true);
            foreach ($out as $id=>$name) {
                $where=['provider'=>$p->key(),'remote_type_id'=>(int)$id];
                if (isset($known[(int)$id])) { Db::name('avdb_provider_type')->where($where)->update(['remote_name'=>mb_substr($name,0,64,'UTF-8'),'updated_at'=>$now]); }
                else { Db::name('avdb_provider_type')->insert($where+['local_type_id'=>0,'remote_name'=>mb_substr($name,0,64,'UTF-8'),'import'=>1,'remote_total'=>0,'total_at'=>0,'updated_at'=>$now]); }
            }
            if (isset($known[0])) { Db::name('avdb_provider_type')->where(['provider'=>$p->key(),'remote_type_id'=>0])->update(['updated_at'=>$now]); }
        } catch (\Throwable $e) { error_log('[AVDB] category cache: '.get_class($e)); }
        return $out;
    }
    private function createJob($owner,array $options)
    {
        if ((int)$owner<1) { throw new \RuntimeException('Authentication required.'); }
        \app\common\model\VodCleanup::assertWritesAllowed();
        $o=self::normalizeOptions($options); if ($this->scheduleContext) { $o['_schedule_client_id']=$this->scheduleContext['client']; $o['_schedule_epoch']=$this->scheduleContext['epoch']; } $caps=$this->capabilities(false);
        $provider=$o['provider']; $pcaps=$this->caps($provider); $legacy=($pcaps['stable_cursor'] ?? '')==='export' && in_array($o['mode'],['selected','pages','all'],true);
        // D-I15: never start writing into a full database.
        if (StorageGuard::refresh()['full']) { throw new \RuntimeException(StorageGuard::message() ?: 'Database or disk is full.'); }
        // R2-7b: a start (web or tools/import.php --start) only closes idle windows the light way; their append runs on the command line
        self::closeIdleBulkWindows(self::BULK_IDLE_CLOSE, true);
        // XVID-NOLIMIT-BRIEF A3: no web size rule; a web job of any size runs in steps and continues by itself (tickWeb).
        // A4: the whole xvid catalog reads the xvidexport snapshot when the provider offers one (--via=windows forces id windows).
        if ($o['mode']==='windows' && (int)$o['max_items']===0 && ($o['via'] ?? '')!=='windows') { $pp=$this->prov($provider); if (method_exists($pp,'cachedManifest') && $pp->cachedManifest()!==null) { $o['mode']='snapshot'; } }
        if ($o['mode']==='presence') { $o['create_categories']=0; }
        // Only newly started full native catalog jobs use durable bounded export.
        // Historical jobs, page ranges and filtered scopes retain their original discovery.
        if ($legacy && self::fullExportOptions($o)) { $o['export_cursor']=0; $o['export_upper']=0; }
        // D-I5 / A3: scopes above 50,000 videos run in bulk mode, web and CLI (category totals are known after the first page).
        $o['bulk']=self::bulkFor($o) ? 1 : 0;
        if ($o['mode']==='category' && $o['bulk']) { $o['bulk']=0; $o['_bulk_pending']=1; }
        $types=array_column($caps['local_categories'],'type_id'); $players=array_column($caps['players'],'id');
        if ($o['default_player']===null) { $o['default_player']=$caps['default_player']; }
        if ($o['default_player']!=='' && !in_array($o['default_player'],$players,true)) { throw new \RuntimeException('Default player is no longer enabled.'); }
        if (!$o['category_map'] && !$o['create_categories'] && $o['mode']!=='presence') { throw new \RuntimeException('Map provider categories before importing.'); }
        foreach ($o['category_map'] as $id) { if (!in_array((int)$id,array_map('intval',$types),true)) { throw new \RuntimeException('Category mapping is no longer valid.'); } }
        foreach ($o['player_map'] as $key) { if (!in_array($key,$players,true)) { throw new \RuntimeException('Player mapping is no longer valid.'); } }
        $this->ensureSchema();
        $classes=[]; $parentMode=!empty($pcaps['category_parent']);
        if ($o['create_categories']) {
            if ($legacy || !$parentMode) {
                if ($provider===self::PROVIDER) { $catalog=$this->prov($provider)->paced(function () { return $this->client->fetch(['ac'=>'list','pagesize'=>1],'en'); }); }
                else { $names=$this->prov($provider)->categories('en'); $catalog=['class'=>array_map(function ($id,$n) { return ['type_id'=>$id,'type_name'=>$n]; },array_keys($names),array_values($names))]; }
                $classes=self::providerCategories($catalog['class'] ?? null);
            } else { $classes=$this->providerClassNames($this->prov($provider)); }
        }
        // D-I12 placement: as new arrivals when the site already holds >= 1,000 videos of another provider.
        if ($o['placement']==='new' && !in_array($o['mode'],['incremental','presence'],true)) {
            $other=Db::name('avdb_import_map')->where('provider','<>',$provider)->where('vod_id','>',0)->field('remote_id')->limit(self::PLACEMENT_MIN_OTHER-1,1)->select();
            $o['_place']=['as_new'=>$other ? 1 : 0,'start'=>$this->now()];
        } else { $o['_place']=['as_new'=>0,'start'=>$this->now()]; }
        if ($provider!==self::PROVIDER) { $o['_vod_cleanup_sealed']='provider:'.$provider; }
        if ($o['bulk']) {
            if (Db::name('avdb_import_job')->where(['provider'=>$provider,'state'=>['in',array_merge(self::RUN_STATES,self::PAUSE_STATES)]])->find()) { throw new \RuntimeException(ImportProviders::text('bulk_busy','Another import of this provider is running or paused. Finish it before starting a large import.')); }
        }
        $id=bin2hex(random_bytes(16)); $lock='avdb_categories_'.substr(hash('sha256',(string)config('database.database').'|'.(string)config('database.prefix')),0,40); $held=false;
        try {
            if ($o['create_categories']) { $result=Db::query('SELECT GET_LOCK(:lock_name,5) AS acquired',['lock_name'=>$lock],true); if ((int)($result[0]['acquired'] ?? 0)!==1) { throw new \RuntimeException('Category setup is busy. Retry starting the import shortly.'); } $held=true; }
            \app\common\util\VodDeletion::withMediaLock(function () use (&$o,$owner,$id,$classes,$legacy,$parentMode,$provider) {
                \app\common\model\VodCleanup::assertWritesAllowed();
            Db::startTrans();
            try {
                $this->startFence($owner);
                // Manual destinations may have changed while waiting for another setup.
                foreach ($o['category_map'] as $local) { if (!Db::name('type')->where(['type_id'=>(int)$local,'type_mid'=>1])->lock(true)->find()) { throw new \RuntimeException('Category mapping is no longer valid.'); } }
                if (!$o['create_categories']) { $o=array_merge($o,['created_category_ids'=>[],'category_setup'=>[]]); }
                elseif ($legacy || !$parentMode) { $o=$this->categorySetup($o,$classes); }
                else { $o['created_category_ids']=[]; $o=$this->categorySetupProvider($o,$this->prov($provider),$classes); }
                if ($o['bulk']) { $o['_vod_min']=(int)Db::name('vod')->max('vod_id')+1; }
                $row=['job_id'=>$id,'admin_id'=>(int)$owner,'options'=>$this->json($o),'state'=>self::stateFor('running',$provider),'phase'=>'discover','page'=>$o['start_page'],'cursor'=>0,'discovery_done'=>0,'lock_token'=>'','lock_until'=>0,'error'=>'','retry_at'=>0,'created_at'=>time(),'updated_at'=>time(),'provider'=>$provider];
                Db::name('avdb_import_job')->insert($row);
                Db::commit();
            } catch (\Throwable $e) { Db::rollback(); throw $e; }
            });
        } finally { if ($held) { Db::query('SELECT RELEASE_LOCK(:lock_name) AS released',['lock_name'=>$lock],true); } }
        if ($o['bulk']) { self::contract('FtIndex','bulkBegin',[self::jobNumber($id),(int)$o['_vod_min']]); }
        if ($o['created_category_ids'] || !empty($o['enabled_parent'])) { try { (new Type())->setCache(); Type::bumpListRevision(true); } catch (\Throwable $e) { error_log('[AVDB] Category cache maintenance: '.$e->getMessage()); } }
        if ($this->categoryAccessDirty) { try { (new Group())->setCache(); } catch (\Throwable $e) { error_log('[AVDB] Group cache maintenance: '.$e->getMessage()); } }
        return $this->status($owner,$id);
    }
    /** D-I5 / A3 (pure): bulk mode for scopes above BULK_MIN videos (0 = all), whatever the runner (web or CLI). */
    public static function bulkFor(array $o)
    {
        return in_array($o['mode'] ?? '',['recent','windows','snapshot','all','category'],true) && ((int)($o['max_items'] ?? 0)===0 || (int)$o['max_items']>self::BULK_MIN);
    }
    private function job($owner,$id)
    {
        if (!$this->ready()) { throw new \RuntimeException('No import jobs yet.'); }
        $job=Db::name('avdb_import_job')->where(['job_id'=>$id,'admin_id'=>(int)$owner])->find(); if (!$job) { throw new \RuntimeException('Import job not found.'); } return $job;
    }
    public function status($owner,$id)
    {
        $j=$this->job($owner,$id); $o=$this->decode($j['options']); $counts=Db::name('avdb_import_item')->where('job_id',$id)->field('state,count(*) n')->group('state')->select();
        // History beyond KEEP_HISTORY_RUNS: removed item rows still count (recorded before removal).
        if (!empty($o['_journal_counts']) && is_array($o['_journal_counts'])) { $live=array_column($counts,'n','state'); $counts=[]; foreach (array_unique(array_merge(array_keys($live),array_keys($o['_journal_counts']))) as $s) { $counts[]=['state'=>$s,'n'=>max((int)($live[$s] ?? 0),(int)($o['_journal_counts'][$s] ?? 0))]; } }
        // Bulk jobs delete their created / skipped item rows after commit (D-I5): those counts live in options._bulk_counts.
        if (!empty($o['_bulk_counts']) && is_array($o['_bulk_counts'])) { $live=array_column($counts,'n','state'); foreach ($o['_bulk_counts'] as $s=>$n) { $live[$s]=(int)($live[$s] ?? 0)+(int)$n; } $counts=[]; foreach ($live as $s=>$n) { $counts[]=['state'=>$s,'n'=>$n]; } }
        $r=['id'=>$id,'state'=>self::publicState($j['state']),'phase'=>$j['phase'],'page'=>(int)$j['page'],'queued'=>0,'processed'=>0,'created'=>0,'updated'=>0,'skipped'=>0,'failed'=>0,'max_items'=>$o['max_items'] ?? 0,'locales'=>$o['locales'] ?? ['en'],'options'=>$o,'error'=>$j['error'],'updated_at'=>(int)$j['updated_at'],'retry_after'=>max(0,(int)$j['retry_at']-time()),'provider'=>self::jobProvider($j),'type'=>$o['type'] ?? 'import','runner'=>$o['runner'] ?? 'web','bulk'=>!empty($o['bulk'])];
        foreach ($counts as $c) { $r['queued']+=(int)$c['n']; if (in_array($c['state'],['created','updated','skipped','failed','rolled_back','rollback_conflict'],true)) { $r['processed']+=(int)$c['n']; $r[$c['state']]=(int)$c['n']; } }
        if ((int)$j['retry_at']>time()) { $state=ProviderPacer::rows()[$r['provider']] ?? []; $r['waiting']=['until'=>(int)$j['retry_at'],'reason'=>(string)($state['last_error'] ?? '')]; }
        foreach (['filtered'=>'_filtered','hash_skipped'=>'_hash_skipped','tombstones_skipped'=>'_tomb_skipped','older_skipped'=>'_older_skipped','feed_requeued'=>'_feed_requeued'] as $k=>$key) { $r[$k]=(int)($o[$key] ?? 0); }
        if (($o['type'] ?? '')==='undo') { $r['deleted']=(int)($o['_deleted'] ?? 0); $r['to_delete']=(int)($o['_to_delete'] ?? 0); }
        $r['stop_reason']=(string)($o['stop_reason'] ?? '');
        if (!empty($o['bulk'])) { $r['ft_keys']=self::ftKeysState(); }
        $r['source']=(string)($o['_source'] ?? '');
        if (!empty($o['snap']) && is_array($o['snap']) && ($o['snap']['phase'] ?? '')!=='off') {
            $sn=$o['snap']; $n=count((array)($sn['shards'] ?? [])); $i=(int)($sn['shard_i'] ?? ($sn['i'] ?? 0));
            $r['snapshot']=['phase'=>(string)($sn['phase'] ?? ''),'shard'=>min($n,$i+1),'shards'=>$n,'line'=>(int)($sn['line'] ?? 0),'mb'=>round((float)($o['_snap_dl_mb'] ?? 0)+(int)($sn['dl_bytes'] ?? ($sn['dl'] ?? 0))/1048576,1),'refused'=>(int)($o['_snap_refused'] ?? 0),'switched'=>(int)($sn['switched'] ?? 0),'feed_rows'=>(int)($o['_feed_rows'] ?? 0),'hidden'=>(int)($o['_hidden'] ?? 0)];
        }
        if (($o['mode'] ?? '')==='presence') { $r['presence']=['seen'=>(int)($o['_seen'] ?? 0),'missing'=>(int)($o['_missing'] ?? 0),'restored'=>(int)($o['_restored'] ?? 0),'hidden'=>(int)($o['_hidden'] ?? 0),'sweep_state'=>(string)($o['sweep_state'] ?? ''),'notice'=>(string)($o['_notice'] ?? '')]; }
        $r['results']=Db::name('avdb_import_item')->where('job_id',$id)->field('remote_id,vod_id,state,message')->order('item_id desc')->limit(50)->select(); $r['results']=$this->resultLocales($r['results']); return $r;
    }
    public function jobs($owner)
    {
        if (!$this->ready()) { return []; }
        $rows=Db::name('avdb_import_job')->where('admin_id',(int)$owner)->field('job_id,state,phase,error,created_at,updated_at')->order('created_at desc')->limit(30)->select();
        foreach ($rows as &$row) { $row['state']=self::publicState($row['state']); } unset($row);
        return $rows;
    }
    public function items($owner,$id,array $options=[])
    {
        $this->job($owner,$id); $state=$options['state'] ?? ''; if ($state==='queued') { $state='pending'; }
        if (!is_string($state) || !in_array($state,['','pending','created','updated','skipped','failed','rolled_back','rollback_conflict'],true)) { throw new \RuntimeException('Invalid import-result state filter.'); }
        $page=$options['pg'] ?? 1; $size=$options['pagesize'] ?? 50;
        foreach ([$page,$size] as $value) { if (!is_scalar($value) || !preg_match('/^[1-9][0-9]*$/D',(string)$value)) { throw new \RuntimeException('Invalid import-result pagination.'); } }
        $page=(int)$page; $size=(int)$size; if ($page>1000000 || $size>100) { throw new \RuntimeException('Import-result page size must be 1 through 100.'); }
        $where=['job_id'=>$id]; if ($state!=='') { $where['state']=$state; }
        $total=(int)Db::name('avdb_import_item')->where($where)->count();
        $rows=Db::name('avdb_import_item')->where($where)->field('item_id,remote_id,vod_id,state,message,locale_cursor')->order('item_id desc')->limit(($page-1)*$size,$size)->select();
        return ['items'=>$this->resultLocales($rows),'total'=>$total,'page'=>$page,'pagesize'=>$size,'has_more'=>$page*$size<$total];
    }
    private function resultLocales(array $rows)
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',array_column($rows,'vod_id'))))); $byId=[];
        if ($ids) { foreach (Db::name('avdb_translation')->where('vod_id','in',$ids)->field('vod_id,locale,payload')->select() as $record) {
            $payload=self::translation($this->decode($record['payload']));
            if (!empty($payload['vod_name']) || !empty($payload['vod_content']) || !empty($payload['vod_blurb'])) { $byId[(int)$record['vod_id']][]=$record['locale']; }
        } }
        foreach ($rows as &$row) { $row['available_locales']=$byId[(int)$row['vod_id']] ?? []; sort($row['available_locales']); $row['translation_count']=count($row['available_locales']); } unset($row); return $rows;
    }
    private function control($owner,$id,$state)
    {
        $controlJob=$this->job($owner,$id); $controlOptions=$this->decode($controlJob['options']); $provider=self::jobProvider($controlJob);
        if ($state==='running' && !empty($controlOptions['_schedule_client_id'])) { throw new \RuntimeException('Use the automatic import controls for this job.'); }
        $j=$this->job($owner,$id); if (in_array($state,['paused','cancelled'],true) && in_array($j['state'],['complete','rolled_back','rollback_conflict'],true)) { return $this->status($owner,$id); } if ($state==='running' && $j['phase']==='rollback') { throw new \RuntimeException('Finish the rollback before resuming this job.'); } if ($state==='running' && (int)$j['retry_at']>time()) { throw new \RuntimeException('Provider rate limit: wait '.((int)$j['retry_at']-time()).' seconds before resuming.'); } if ((int)$j['lock_until']>time()) { throw new \RuntimeException('A step is running. Try again shortly.'); }
        if ($state==='running' && in_array($j['state'],['complete','rolled_back','rollback_conflict'],true)) { throw new \RuntimeException('This job has finished.'); }
        $set=['state'=>self::stateFor($state,$provider),'error'=>'','updated_at'=>time()];
        if ($state==='running') {
            // The admin's resume accepts a held batch: the update anomaly (D-I8b) / the dropped images (2.4).
            $changedOptions=false;
            if (!empty($controlOptions['_anomaly_hold'])) { $controlOptions['_anomaly_ack']=(int)$controlOptions['_anomaly_hold']; unset($controlOptions['_anomaly_hold']); $changedOptions=true; }
            // import-r2 N3: the feed changes the admin accepted no longer count toward the 24 h feed rule
            if (!empty($controlOptions['_feed_hold'])) { $controlOptions['_feed_acked']=(int)($controlOptions['_feed_changed'] ?? 0); unset($controlOptions['_feed_hold']); $changedOptions=true; }
            if (!empty($controlOptions['_host_hold'])) { $controlOptions['_host_ack']=(int)$controlOptions['_host_hold']; unset($controlOptions['_host_hold'],$controlOptions['_host_hold_hosts']); $changedOptions=true; }
            if (!empty($controlOptions['_size_hold'])) { throw new \RuntimeException(ImportProviders::text('size_bound_resume','This import stopped at its size bound. Start a new import if the larger catalog is expected.')); }
            // A bulk job whose search window was closed while it was paused opens a new window above the indexed rows.
            if (!empty($controlOptions['bulk']) && !empty($controlOptions['_bulk_closed'])) { $controlOptions['_bulk_open']=(int)Db::name('vod')->max('vod_id')+1; unset($controlOptions['_bulk_closed']); $changedOptions=true; $reopen=(int)$controlOptions['_bulk_open']; }
            if ($changedOptions) { $set['options']=$this->json($controlOptions); }
        }
        $changed=Db::name('avdb_import_job')->where(['job_id'=>$id,'admin_id'=>(int)$owner,'state'=>$j['state'],'lock_until'=>['elt',time()]])->update($set);
        if ($changed && !empty($reopen)) { self::contract('FtIndex','bulkBegin',[self::jobNumber($id),$reopen]); }
        // engines F13: a cancelled bulk job closes its search window now (bulkEnd), or every later video stays unsearchable.
        if ($changed && $state==='cancelled' && !empty($controlOptions['bulk'])) { self::closeBulkWindow($id,'cancelled',PHP_SAPI!=='cli'); self::ftKeysRelease($id); }
        if (in_array($state,['paused','cancelled'],true) && !empty($controlOptions['_schedule_client_id'])) {
            Db::name('avdb_import_schedule')->where(['client_id'=>$controlOptions['_schedule_client_id'],'admin_id'=>(int)$owner,'epoch'=>(int)$controlOptions['_schedule_epoch']])->update(['epoch'=>(int)$controlOptions['_schedule_epoch']+1,'enabled'=>0,'state'=>'paused','lease_token'=>'','lease_until'=>0,'error'=>'Import stopped by administrator.','updated_at'=>time()]);
        }
        // A cancelled job is finished: an older job may have just left the newest KEEP_FULL_RUNS.
        if ($state==='cancelled' && $changed) { $this->trimHistoryQuietly(); }
        return $this->status($owner,$id);
    }
    public function pause($owner,$id) { return $this->control($owner,$id,'paused'); }
    public function cancel($owner,$id) { return $this->control($owner,$id,'cancelled'); }
    public function resume($owner,$id) { return \app\common\util\VodDeletion::withMediaLock(function () use ($owner,$id) { \app\common\model\VodCleanup::assertWritesAllowed(); \app\common\model\VodCleanup::assertImportJobAllowed($this->job($owner,$id)); Db::startTrans(); try { $this->startFence($owner,$id); $result=$this->control($owner,$id,'running'); Db::commit(); return $result; } catch (\Throwable $e) { Db::rollback(); throw $e; } }); }
    /**
     * 2.4 "The provider uses a new image host <host>: allow it?" -> the host joins the per-site list of the provider
     * (ImportProviders::allowImageHost, maccms.php import.image_hosts_extra) and the job resumes.
     */
    public function allowImageHost($owner,$id,$host)
    {
        $j=$this->job($owner,$id); $o=$this->decode($j['options']);
        $host=strtolower(trim((string)$host));
        if (!in_array($host,(array)($o['_host_hold_hosts'] ?? []),true)) { throw new \RuntimeException(ImportProviders::text('host_not_reported','This image host was not reported by the paused import.')); }
        ImportProviders::allowImageHost(self::jobProvider($j),$host);
        $o['image_hosts_extra']=array_values(array_unique(array_merge((array)($o['image_hosts_extra'] ?? []),[$host])));
        Db::name('avdb_import_job')->where(['job_id'=>$id,'admin_id'=>(int)$owner])->update(['options'=>$this->json($o)]);
        return $this->resume($owner,$id);
    }
    public function retry($owner,$id)
    {
        $retryJob=$this->job($owner,$id); $retryOptions=$this->decode($retryJob['options']); $provider=self::jobProvider($retryJob);
        if (!empty($retryOptions['_schedule_client_id'])) { throw new \RuntimeException('Use the automatic import controls for this job.'); }
        list($j,$token)=\app\common\util\VodDeletion::withMediaLock(function () use ($owner,$id) {
            \app\common\model\VodCleanup::assertWritesAllowed();
            \app\common\model\VodCleanup::assertImportJobAllowed($this->job($owner,$id));
            return $this->lock($owner,$id);
        });
        try {
            if (in_array($j['state'],['rolled_back','rollback_conflict'],true) || $j['phase']==='rollback') { throw new \RuntimeException('A rolled-back job cannot be retried.'); }
            if ((int)$j['retry_at']>time()) { throw new \RuntimeException('Provider rate limit: wait '.((int)$j['retry_at']-time()).' seconds before retrying.'); }
            $where=['job_id'=>$id,'state'=>'failed','payload'=>['neq','{}']];
            if (!Db::name('avdb_import_item')->where($where)->count()) { throw new \RuntimeException('There are no failed imported items to retry. Resume a paused connection failure, or start a new job for missing English IDs.'); }
            \app\common\util\VodDeletion::withMediaLock(function () use ($owner,$id,$token,$where,$provider) {
                Db::startTrans(); try {
                    $this->fence($id,$token);
                    $this->startFence($owner,$id);
                    Db::name('avdb_import_item')->where($where)->update(['state'=>'pending','message'=>'']);
                    Db::name('avdb_import_job')->where(['job_id'=>$id,'lock_token'=>$token])->update(['state'=>self::stateFor('running',$provider),'phase'=>'apply','error'=>'','retry_at'=>0,'updated_at'=>time()]);
                    Db::commit();
                } catch (\Throwable $e) { Db::rollback(); throw $e; }
            });
        } finally { $this->flushCatalog(); Db::name('avdb_import_job')->where(['job_id'=>$id,'lock_token'=>$token])->update(['lock_token'=>'','lock_until'=>0,'updated_at'=>time()]); }
        return $this->status($owner,$id);
    }
    private function lock($owner,$id)
    {
        $j=$this->job($owner,$id); $token=bin2hex(random_bytes(16));
        $ok=Db::name('avdb_import_job')->where(['job_id'=>$id,'admin_id'=>(int)$owner,'lock_until'=>['elt',time()]])->update(['lock_token'=>$token,'lock_until'=>time()+300]);
        if (!$ok) { throw new \RuntimeException('A step is already running.'); } $j=$this->job($owner,$id); return [$j,$token];
    }
    private function failureMessage(\Throwable $error)
    {
        if (get_class($error)==='RuntimeException' || $error instanceof \InvalidArgumentException || $error instanceof AvdbImportLeaseException || $error instanceof \app\common\util\AvdbClientException || $error instanceof \app\common\util\ProviderHttpException) { return mb_substr($error->getMessage(),0,1000); }
        error_log('[AVDB import] '.get_class($error).': '.$error->getMessage());
        return 'This import request could not complete. Check the server error log before trying again.';
    }
    private function fence($id,$token)
    {
        // The job row stays locked for each short checkpoint transaction. A lease
        // takeover cannot race committed item/catalog writes or their beforeimages.
        $job=Db::name('avdb_import_job')->where('job_id',$id)->lock(true)->find();
        if (!$job || $job['lock_token']!==$token || (int)$job['lock_until']<=time()) { throw new AvdbImportLeaseException('Import step lease expired; resume the job to continue safely.'); }
        $this->scheduleFence();
        Db::name('avdb_import_job')->where(['job_id'=>$id,'lock_token'=>$token])->update(['lock_until'=>time()+300]);
    }
    /** Write a checkpoint (job columns and/or options) under the lease fence. */
    private function checkpoint($jobId,$token,array $set)
    {
        Db::startTrans(); try { $this->fence($jobId,$token); Db::name('avdb_import_job')->where(['job_id'=>$jobId,'lock_token'=>$token])->update($set); Db::commit(); } catch (\Throwable $e) { Db::rollback(); throw $e; }
    }
    /** Pause the job with a readable message (the job keeps its items; resume continues). */
    private function hold($j,$token,array $o,$message)
    {
        $this->checkpoint($j['job_id'],$token,['state'=>self::stateFor('paused',self::jobProvider($j)),'error'=>mb_substr($message,0,1000,'UTF-8'),'options'=>$this->json($o)]);
        self::trace('hold:'.mb_substr($message,0,60,'UTF-8'));
    }
    /**
     * import-r2 N8 (pure): end of a step's work. The budget counts from the request start when it is known ($requestStart, the
     * web: MAC_START_TIME of the entry script) and not more than half the budget in the past, else from the step start; the
     * work ends STEP_END_RESERVE before it, so the step's last batch, its status answer and the framework's own time stay
     * inside the budget (9 s web steps took 9.15-9.27 s per request). At least 0.5 s of work is always left.
     */
    public static function stepDeadline($started, $budget, $requestStart = null)
    {
        $started = (float)$started; $budget = max(1.0, (float)$budget); $origin = $started;
        if ($requestStart !== null && (float)$requestStart <= $started && $started - (float)$requestStart < $budget / 2) { $origin = (float)$requestStart; }
        return max($started + 0.5, $origin + $budget - self::STEP_END_RESERVE);
    }
    /**
     * Round-3 verify fix (N18 re-measure, pure): lane H's per-request database deadline (DbTimeout: a front-end request, the
     * background tick after its response included, has its statement limit + 1 s from the request start; after that every
     * statement is refused at once) caps the step's work STEP_DB_RESERVE before it, so the last batch, the checkpoint and the
     * lease release still run (a 3.9 s background step on a T3 site had its later statements refused, the lease release too, and
     * the job waited 300 s). $dbLeft = DbTimeout::remaining() (null = no deadline: command line, admin pages).
     */
    public static function stepDeadlineDb($deadline, $now, $dbLeft)
    {
        if ($dbLeft === null) { return (float)$deadline; }
        return min((float)$deadline, (float)$now + (float)$dbLeft - self::STEP_DB_RESERVE);
    }
    /** Seconds of database time this request has left under lane H's per-request deadline (null = none or not installed). */
    private static function dbTimeLeft()
    {
        $cls = '\\app\\common\\util\\DbTimeout';
        if (!class_exists($cls) || !method_exists($cls, 'remaining')) { return null; }
        try { $left = call_user_func([$cls, 'remaining']); } catch (\Throwable $e) { return null; }
        return $left === null ? null : (float)$left;
    }
    /**
     * One step. $budget = seconds of work (web and callback 9, the CLI runner more, D-I1 / IMP 3). A step ends early
     * when the provider's clock is busy (web), the job was paused from outside, or a batch was held.
     */
    public function step($owner,$id,$budget=9)
    {
        $initial=$this->job($owner,$id); $initialOptions=$this->decode($initial['options']);
        if (!empty($initialOptions['_schedule_client_id']) && !$this->scheduleContext) { throw new \RuntimeException('Use the automatic import controls for this job.'); }
        $this->playerKeys=null;
        self::upgradeSchema();
        list($j,$token)=\app\common\util\VodDeletion::withMediaLock(function () use ($owner,$id) {
            \app\common\model\VodCleanup::assertWritesAllowed();
            \app\common\model\VodCleanup::assertImportJobAllowed($this->job($owner,$id));
            return $this->lock($owner,$id);
        });
        $finished=false; $provider=self::jobProvider($j); $failure=null; $this->stepUnits=0; $this->inFlight=null; $this->smallBatches=false;
        try {
            if (!in_array($j['state'],self::RUN_STATES,true) || (int)$j['retry_at']>time()) {
                // P-O1: a step on a finished bulk job continues a re-add of the filter FULLTEXT keys that its last step cut short
                if (in_array($j['state'],self::FINISHED_JOB_STATES,true) && !empty($initialOptions['bulk'])) { self::ftKeysRestore(PHP_SAPI==='cli' ? 0 : self::FT_WEB_BUDGET); }
            } else {
            $budget=max(1.0,min(3600.0,(float)$budget));
            // import-r2 N8: the work stops STEP_END_RESERVE before the budget, counted from the request start on the web (the framework's
            // time before and after the step is inside the budget: web steps of 9 s took 9.15-9.27 s per request)
            $started=microtime(true); $this->stepDeadline=self::stepDeadline($started,$budget,PHP_SAPI!=='cli' && defined('MAC_START_TIME') ? (float)MAC_START_TIME : null);
            // round 3 (N18 re-measure): never past lane H's per-request database deadline (front-end requests and their background tick)
            $this->stepDeadline=self::stepDeadlineDb($this->stepDeadline,microtime(true),self::dbTimeLeft());
            // round 4 (flood-r4 N17): a web caller with little database time left works in small batches and snapshot pages
            $dbLeft=self::dbTimeLeft(); $this->smallBatches=PHP_SAPI!=='cli' && $dbLeft!==null && $dbLeft<self::STEP_SMALL_DB_S;
            $this->stepYield=false;
            $p=$this->prov($provider); if (method_exists($p,'pacer') && $p->pacer()) { $p->pacer()->last=null; }
            do {
                $j=$this->job($owner,$id); $o=$this->decode($j['options']);
                if (!in_array($j['state'],self::RUN_STATES,true)) { break; }
                if ($this->scheduleContext && (($o['_schedule_client_id']??'')!==$this->scheduleContext['client'] || (int)($o['_schedule_epoch']??0)!==$this->scheduleContext['epoch'])) { throw new AvdbImportLeaseException('Automatic import settings changed.'); }
                $this->bulk=!empty($o['bulk']);
                // round 4 (flood-r4 N17): every unit of work starts only when its expected time ends before the work deadline (else the
                // unit is made smaller or the step ends here: the next step does it with time of its own)
                if (($o['type'] ?? 'import')==='undo') { // D-I13: at most 500 videos per step
                    $n=self::batchRows(self::UNDO_STEP,$this->stepDeadline-microtime(true),self::undoSpr($o));
                    if ($n<1) { self::trace('step_cap:undo'); break; }
                    if ($this->undoStep($j,$o,$token,$n)) { $finished=true; } $this->stepUnits++; break;
                }
                if (($o['mode'] ?? '')==='presence') {
                    if (!self::workFits(microtime(true),$this->stepDeadline,$this->discoverExpected($o))) { self::trace('step_cap:presence'); break; }
                    if ($this->presenceStep($j,$o,$token)) { $finished=true; break; } $this->stepUnits++; continue;
                }
                $limit=array_key_exists('export_cursor',$o)?min(500,max(1,(int)($o['_export_batch_limit'] ?? 500))):($this->bulk?500:100);
                if ($this->smallBatches) { $limit=min($limit,self::STEP_SMALL_BATCH); }
                $n=self::batchRows($limit,$this->stepDeadline-microtime(true),$this->stepSpr($o));
                $pending=Db::name('avdb_import_item')->where(['job_id'=>$id,'state'=>'pending'])->order('item_id')->limit(max(1,$n))->select();
                if ($pending) {
                    if ($n<1) { self::trace('step_cap:apply'); break; }
                    $res=$this->processBatch($j,$o,$pending,$token);
                    if ($res==='held' || $res==='cap') { break; }
                }
                elseif (!(int)$j['discovery_done']) {
                    if (!self::workFits(microtime(true),$this->stepDeadline,$this->discoverExpected($o))) { self::trace('step_cap:discover'); break; }
                    $this->inFlight=['discover',microtime(true),0]; $this->discover($j,$o,$token); $this->inFlight=null; $this->stepUnits++;
                }
                else {
                    if (!self::workFits(microtime(true),$this->stepDeadline,self::STEP_FINISH_MIN_S)) { self::trace('step_cap:finish'); break; }
                    $this->finishJob($j,$o,$token); $finished=true; break;
                }
                // Image staging performs only one download in each request.
                if (!empty($o['download_images'])) { break; }
                if ($this->stepYield) { break; }
            } while (microtime(true)<$this->stepDeadline);
            }
        } catch (\Throwable $e) { $failure=$e; }
        // round 4 (flood-r4 N17): the end of the step (the failure write, the catalog flush, the lease release, the status answer) runs
        // with database time of its own when the request's deadline is (nearly) spent: a cut step never keeps its 300 s lease
        $st=self::withDbTime(function () use ($owner,$id,$j,$token,$failure) {
            try { if ($failure!==null) { $this->stepFailed($j,$token,$failure); } }
            finally {
                try { $this->flushCatalog(); } catch (\Throwable $fe) { error_log('[AVDB import] catalog flush: '.get_class($fe).': '.$fe->getMessage()); }
                Db::name('avdb_import_job')->where(['job_id'=>$id,'lock_token'=>$token])->update(['lock_token'=>'','lock_until'=>0,'updated_at'=>time()]);
            }
            return $this->status($owner,$id);
        });
        if ($finished) { $this->trimHistoryQuietly(); }
        return $st;
    }
    /**
     * Round-4 verify fix (flood-r4 N17, pure): expected seconds of a unit of $rows rows at $spr seconds per row: rows x $spr x
     * STEP_SAFETY, at least STEP_BATCH_MIN_S.
     */
    public static function batchExpected($rows, $spr)
    {
        return max(self::STEP_BATCH_MIN_S, max(0, (int)$rows) * max(0.0, (float)$spr) * self::STEP_SAFETY);
    }
    /**
     * Round-4 verify fix (pure): rows of the next batch that fit into $room seconds (work deadline - now): $limit when its expected
     * time fits, else the largest count whose expected time fits (at least min($limit, STEP_BATCH_MIN_ROWS)), else 0 (the step ends).
     */
    public static function batchRows($limit, $room, $spr)
    {
        $limit = max(0, (int)$limit); $room = (float)$room; $spr = max(1e-6, (float)$spr);
        if ($limit === 0 || $room < self::STEP_BATCH_MIN_S) { return 0; }
        if (self::batchExpected($limit, $spr) <= $room) { return $limit; }
        $n = (int)floor($room / ($spr * self::STEP_SAFETY));
        return $n >= min($limit, self::STEP_BATCH_MIN_ROWS) ? min($limit, $n) : 0;
    }
    /**
     * Round-4 verify fix (pure): rows of the next bulk chunk (at most BULK_TX of the $left rows of the batch) whose expected time
     * (rows x $spr x STEP_SAFETY) plus $post (the end-of-batch work) fits into $room seconds; at least min($left,
     * STEP_BATCH_MIN_ROWS) rows, else 0 (the step ends).
     */
    public static function chunkRows($left, $room, $spr, $post)
    {
        $left = max(0, (int)$left); $spr = max(1e-6, (float)$spr);
        $n = (int)min(self::BULK_TX, $left, floor(max(0.0, (float)$room - max(0.0, (float)$post)) / ($spr * self::STEP_SAFETY)));
        return $left > 0 && $n >= min($left, self::STEP_BATCH_MIN_ROWS) ? $n : 0;
    }
    /** Round-4 verify fix (pure): a unit of $expected seconds started at $now ends before $deadline. */
    public static function workFits($now, $deadline, $expected)
    {
        return (float)$now + max(0.0, (float)$expected) <= (float)$deadline;
    }
    /**
     * Seconds per applied row for the admission of this job's next batch: the larger of its recent measurement (_spr, set per
     * batch and raised by a cut batch; before that its average _rate_s / _rate_n, else STEP_SPR_DEFAULT) and this process's last batch.
     */
    private function stepSpr(array $o)
    {
        $spr = (float)($o['_spr'] ?? 0);
        if ($spr <= 0) { $n = (int)($o['_rate_n'] ?? 0); $spr = $n >= self::STEP_BATCH_MIN_ROWS && (float)($o['_rate_s'] ?? 0) > 0 ? (float)$o['_rate_s'] / $n : self::STEP_SPR_DEFAULT; }
        if ($this->lastSpr !== null) { $spr = max($spr, (float)$this->lastSpr); }
        return max(1e-5, $spr);
    }
    /** Expected seconds of the job's next discovery page (or presence window): measured (_disc_s, this process's last) x STEP_SAFETY, at least STEP_BATCH_MIN_S. */
    private function discoverExpected(array $o)
    {
        $s = max(0.0, (float)($o['_disc_s'] ?? 0));
        if ($this->lastDiscSec !== null) { $s = max($s, (float)$this->lastDiscSec); }
        return max(self::STEP_BATCH_MIN_S, $s * self::STEP_SAFETY);
    }
    /** Seconds per deleted video of an undo job (measured, else 50 videos per second as undoRate()). */
    private static function undoSpr(array $o)
    {
        $n = (int)($o['_deleted'] ?? 0); $s = (float)($o['_undo_s'] ?? 0);
        return $n >= self::STEP_BATCH_MIN_ROWS && $s > 0 ? $s / $n : 0.02;
    }
    /** C11: a statement timeout or a spent per-request database deadline (lane H's DbTimeout::is). */
    private static function isDbTimeout(\Throwable $e)
    {
        $cls = '\\app\\common\\util\\DbTimeout';
        try { return class_exists($cls) && method_exists($cls, 'is') && call_user_func([$cls, 'is'], $e) === true; } catch (\Throwable $x) { return false; }
    }
    /** Errors that end the step and are never an item's failure: a lost lease, a full disk, the database time limit (round 4). */
    private static function stepFatal(\Throwable $e)
    {
        return $e instanceof AvdbImportLeaseException || StorageGuard::isStorageError($e) || self::isDbTimeout($e);
    }
    /** Database time the end of a step needs: min(STEP_EPILOGUE_DB_S, the tier statement limit). */
    private static function epilogueDbSeconds()
    {
        $need = self::STEP_EPILOGUE_DB_S;
        $cls = '\\app\\common\\util\\ScaleTier';
        try { if (class_exists($cls) && method_exists($cls, 'get')) { $t = call_user_func([$cls, 'get']); if (is_array($t) && (int)($t['stmt_limit'] ?? 0) > 0) { $need = min($need, (float)$t['stmt_limit']); } } } catch (\Throwable $e) { /* tier not ready: the default */ }
        return max(0.5, $need);
    }
    /**
     * Round-4 verify fix (flood-r4 N17): runs $fn with lane H's per-request database deadline renewed to epilogueDbSeconds() from
     * now when less than that is left; DbTimeout then sets the session statement limit to the time left again (never above the
     * tier statement limit), so the statement limit still applies. The request's own deadline (and the session limit as last set)
     * is restored afterwards. No deadline (command line, admin pages) or no DbTimeout: $fn runs as is.
     */
    private static function withDbTime(callable $fn)
    {
        $cls = '\\app\\common\\util\\DbTimeout';
        $left = self::dbTimeLeft();
        if ($left === null || !method_exists($cls, 'setDeadline')) { return $fn(); }
        $need = self::epilogueDbSeconds();
        if ($left >= $need) { return $fn(); }
        $at = microtime(true) + $left;
        $capBefore = method_exists($cls, 'sessionCap') ? call_user_func([$cls, 'sessionCap']) : null;
        call_user_func([$cls, 'setDeadline'], microtime(true) + $need, null);
        self::trace('db_epilogue');
        try { return $fn(); }
        finally {
            // the session limit as the last statement left it (DbTimeout set it again when a statement ran), else as before
            $cap = method_exists($cls, 'sessionCap') ? call_user_func([$cls, 'sessionCap']) : null;
            if ($cap === null) { $cap = $capBefore; }
            call_user_func([$cls, 'setDeadline'], $at, $cap === null ? null : (float)$cap);
        }
    }
    /**
     * Error at step level (D-I6): a busy provider clock or a provider wait keeps the job running with retry_at; 429,
     * 5xx, timeouts and challenges keep it running with the pacer's retry_at (8 failures in a row pause it with the
     * pacer's message); storage errors trip the guard and pause the job and every schedule without retry; anything else
     * pauses the job with its message (as in 1.1.0).
     */
    private function stepFailed($j,$token,\Throwable $e)
    {
        $id=$j['job_id']; $provider=self::jobProvider($j); $now=time();
        $where=['job_id'=>$id,'lock_token'=>$token];
        if ($e instanceof ProviderWaitException) {
            Db::name('avdb_import_job')->where($where)->update(['retry_at'=>max($now+1,(int)$e->retryAt),'error'=>'']);
            self::trace('wait:'.$e->reason); return;
        }
        if (StorageGuard::isStorageError($e)) {
            StorageGuard::trip($e); $message=StorageGuard::message() ?: 'Database or disk is full.';
            Db::name('avdb_import_job')->where($where)->update(['state'=>self::stateFor('paused',$provider),'error'=>$message,'retry_at'=>0]);
            self::pauseForStorage($message); return;
        }
        // round 4 (flood-r4 N17): the request's database time ran out, or one statement reached the statement limit. Every unit
        // commits as a whole (a chunk, an item, a discovery page), so nothing of the cut unit was written: the job keeps running
        // and the next step does it again, with the cut unit's time learned (_spr / _disc_s / _post_s). From the second cut step
        // in a row without progress the next step waits (back-off up to DB_CUT_BACKOFF_MAX); the job is never paused for it.
        if (self::isDbTimeout($e)) {
            $o=$this->decode(Db::name('avdb_import_job')->where('job_id',$id)->value('options'));
            $cuts=$this->stepUnits>0 ? 1 : (int)($o['_db_cuts'] ?? 0)+1;
            $o['_db_cuts']=$cuts; $o['_db_cut_at']=$now; $o['_db_cut_total']=(int)($o['_db_cut_total'] ?? 0)+1;
            if (is_array($this->inFlight)) {
                $el=max(0.0,microtime(true)-(float)$this->inFlight[1]);
                if ($this->inFlight[0]==='apply' && (int)$this->inFlight[2]>0) { $o['_spr']=round(max((float)($o['_spr'] ?? 0),$el/(int)$this->inFlight[2]),5); }
                elseif ($this->inFlight[0]==='discover') { $o['_disc_s']=round(max((float)($o['_disc_s'] ?? 0),$el),3); }
                elseif ($this->inFlight[0]==='post') { $o['_post_s']=round(max((float)($o['_post_s'] ?? 0),$el),3); }
            }
            $retry=$cuts>=2 ? $now+(int)min(self::DB_CUT_BACKOFF_MAX,self::TICK_EVERY*(2 ** min(10,$cuts-2))) : 0;
            Db::name('avdb_import_job')->where($where)->update(['retry_at'=>$retry,'error'=>'','options'=>$this->json($o)]);
            if ($cuts===2) { error_log('[AVDB import] job '.$id.': two steps in a row cut by the database time limit; the next step waits '.($retry-$now).' s.'); }
            self::trace('db_cut:'.$cuts); return;
        }
        $kind=ProviderPacer::classify($e)['kind'];
        if (in_array($kind,['rate_limit','transient','challenge'],true)) {
            $p=$this->providers[$provider] ?? null; $v=($p && method_exists($p,'pacer') && $p->pacer()) ? $p->pacer()->last : null;
            if (!$v) { $v=ProviderPacer::decide([], ProviderPacer::classify($e), 1000, $now); unset($v['row']); }
            if (($v['verdict'] ?? '')==='pause') { Db::name('avdb_import_job')->where($where)->update(['state'=>self::stateFor('paused',$provider),'error'=>mb_substr((string)$v['message'],0,1000,'UTF-8'),'retry_at'=>0]); self::trace('provider_pause'); return; }
            Db::name('avdb_import_job')->where($where)->update(['retry_at'=>max($now+1,(int)($v['retry_at'] ?? $now+60)),'error'=>'']);
            self::trace('provider_retry:'.$kind); return;
        }
        Db::name('avdb_import_job')->where($where)->update(['state'=>self::stateFor('paused',$provider),'error'=>$this->failureMessage($e),'retry_at'=>0]);
    }
    /** Job end: complete, then the search / counts / caches (C1 C3 C4 C9; never bumpGeneration). */
    private function finishJob($j,array $o,$token)
    {
        $this->checkpoint($j['job_id'],$token,['state'=>'complete','phase'=>'complete']);
        $provider=self::jobProvider($j);
        $ftvIds=array_values($this->touched); // ft-vocab (fp-port): see below
        if (!empty($o['bulk'])) {
            $max=(int)Db::name('vod')->max('vod_id');
            // R2-7b: older windows of this job that a light close handed to the command line are appended here first (older docs
            // before the newer ones of the current window); a web step leaves them to runDeferredAppends()
            if (PHP_SAPI==='cli') {
                foreach (array_merge((array)($o['_bulk_appending'] ?? []),(array)($o['_bulk_append'] ?? [])) as $r) {
                    if (self::validRange($r)) { $res=self::contract('FtIndex','bulkEnd',[self::jobNumber($j['job_id']),(int)$r[0],(int)$r[1],!empty($r[2])]); $o['_bulk_end_deferred']=is_string($res) ? $res : ''; }
                }
                unset($o['_bulk_append'],$o['_bulk_appending']);
            }
            if (empty($o['_bulk_closed'])) {
                $r=self::contract('FtIndex','bulkEnd',[self::jobNumber($j['job_id']),(int)($o['_bulk_open'] ?? $o['_vod_min'] ?? $max+1),$max,!empty($o['_place']['as_new'])]);
                $o['_bulk_end']=is_string($r) ? $r : '';
            }
            $o['_bulk_closed']=time(); $o['_vod_max']=$max;
            // P-O1: give the filter FULLTEXT keys back (CLI: all of them now; web: as many as fit FT_WEB_BUDGET, the rest by the next calls)
            $fk=self::ftKeysRelease($j['job_id']); if ($fk && ($fk['restored'] || $fk['pending'])) { $o['_ft_keys']=['restored'=>$fk['restored'],'pending'=>$fk['pending'],'seconds'=>$fk['seconds'],'error'=>$fk['error']]; }
        } else {
            // R2-7b: other jobs' idle windows; a web request closes them the light way (no append inline)
            self::closeIdleBulkWindows(self::BULK_IDLE_CLOSE, PHP_SAPI!=='cli');
            if ($this->touched) { self::contract('FtIndex','touch',[array_values($this->touched)]); $this->touched=[]; }
            self::contract('FtIndex','drain',[500, PHP_SAPI==='cli' ? 20 : 2]);
        }
        // R2-7b: appends a light close handed to the command line (other jobs) run here when this job ends there
        if (PHP_SAPI==='cli') { self::runDeferredAppends(false,$j['job_id']); }
        // ft-vocab (fp-port): the job's last videos (and any backlog left by a busy lock) reach the filter vocabulary now
        self::contract('FilterVocab','imported',[$ftvIds]);
        self::contract('ScaleCounts','markDirty',['import']);
        self::contract('ScaleTier','refresh');
        self::contract('Vod','bumpListRevision',[true]);
        if (self::contract('FtIndex','termsDue')===true) { $o['_terms_due']=time(); if (PHP_SAPI==='cli') { self::contract('FtIndex','terms'); } }
        if (!empty($o['_newest'])) { try { $p=$this->prov($provider); if (method_exists($p,'pacer') && $p->pacer()) { $p->pacer()->setNewestRemoteTime((int)$o['_newest']); } } catch (\Throwable $e) { /* informational */ } }
        Db::name('avdb_import_job')->where('job_id',$j['job_id'])->update(['options'=>$this->json($o)]);
    }

    /**
     * Close the search-index bulk window of bulk job $jobId (C1 bulkEnd over the rows it imported since the window
     * opened) when it is open; records _bulk_closed, _vod_max (undo range) and _bulk_end. Safe to call twice.
     * $light (R2-7b): a request that must stay short closes it without running a long append (lightClose()).
     * Returns 'appended' | 'rebuild_due' | 'deferred' (append handed to the command line) | 'queued' (videos queued for the
     * drain), '' when nothing was open.
     */
    public static function closeBulkWindow($jobId, $why = '', $light = false)
    {
        try { $job=Db::name('avdb_import_job')->where('job_id',(string)$jobId)->field('job_id,state,options,lock_until')->find(); } catch (\Throwable $e) { return ''; }
        if (!$job) { return ''; }
        $o=json_decode((string)$job['options'],true); $o=is_array($o) ? $o : [];
        if (empty($o['bulk']) || !empty($o['_bulk_closed']) || in_array($job['state'],self::RUN_STATES,true) || (int)$job['lock_until']>time()) { return ''; }
        $max=(int)Db::name('vod')->max('vod_id');
        $min=(int)($o['_bulk_open'] ?? $o['_vod_min'] ?? $max+1);
        $asNew=!empty($o['_place']['as_new']);
        $r=$light ? self::lightClose($job['job_id'],$min,$max,$asNew,$o) : self::contract('FtIndex','bulkEnd',[self::jobNumber($job['job_id']),$min,$max,$asNew]);
        $o['_bulk_closed']=time(); $o['_vod_max']=max((int)($o['_vod_max'] ?? 0),$max); $o['_bulk_end']=is_string($r) ? $r : ''; if ($why!=='') { $o['_bulk_closed_why']=(string)$why; }
        // state-guarded: when the admin resumed the job meanwhile, nothing is recorded and its own end appends from its window start
        Db::name('avdb_import_job')->where(['job_id'=>$job['job_id'],'state'=>$job['state']])->update(['options'=>json_encode($o,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        self::trace('bulk_window_closed:'.$why.($light ? ':light:'.$o['_bulk_end'] : ''));
        return $o['_bulk_end'];
    }
    /**
     * Close the bulk windows of bulk jobs that are paused (or stopped) for longer than $idle seconds, and of cancelled
     * / finished bulk jobs that still hold one (1.2.0 builds before the verify fix). Called when any job starts or
     * finishes, by tools/import.php --tick and by housekeeping (hand-over to lane H). Returns the windows closed.
     * $light (R2-7b): null = every request except the command line; a job start passes true (CLI included). A light call
     * never runs a long append (lightClose()); a command-line call also runs the appends light closes handed to it.
     */
    public static function closeIdleBulkWindows($idle = self::BULK_IDLE_CLOSE, $light = null)
    {
        $light=$light===null ? PHP_SAPI!=='cli' : (bool)$light;
        $n=0;
        try { $rows=Db::name('avdb_import_job')->where('options','like','%"bulk":1%')->where('state','not in',self::RUN_STATES)->where('updated_at','<',time()-max(0,(int)$idle))->field('job_id,state,options')->order('updated_at desc')->limit(50)->select(); }
        catch (\Throwable $e) { return 0; }
        foreach ($rows as $row) {
            $o=json_decode((string)$row['options'],true);
            if (!is_array($o) || empty($o['bulk']) || !empty($o['_bulk_closed'])) { continue; }
            if (self::closeBulkWindow($row['job_id'],in_array($row['state'],self::PAUSE_STATES,true) ? 'idle' : $row['state'],$light)!=='') { $n++; }
        }
        // R2-7b: the command line runs the appends handed to it; a light call only queues those the command line left too long
        self::runDeferredAppends($light);
        // P-O1: filter FULLTEXT keys of a bulk job that ended, stays paused or lost its runner come back here too
        self::ftKeysRestore(PHP_SAPI==='cli' ? 0 : self::FT_WEB_BUDGET);
        return $n;
    }
    /**
     * R2-7b: close bulk window [$min, $max] of job $jobId without a long append. Published videos in the window (counted
     * along the primary key, at most LIGHT_QUEUE_MAX + 1):
     *  - none or at most LIGHT_APPEND_MAX: C1 bulkEnd inline (one batch, a fraction of a second);
     *  - not placed as new arrivals, or more than APPEND_DOC_RATIO of the search docs: rebuild_due 'bulk' and the window
     *    closes (what bulkEnd itself decides for such a window, without its count over the whole range);
     *  - else, with a command-line runner seen in the last 24 h: the window closes now and the sorted append is recorded in
     *    $o['_bulk_append'] ([min, max, as_new, at]) for runDeferredAppends() (tools/import.php --run / --tick);
     *  - else (a site without cron): the videos are queued for the budgeted drain (at most LIGHT_QUEUE_MAX), else rebuild_due.
     * The window closes through bulkEnd over the empty range [$min, $min - 1] (vod_ft_state.bulk_job = 0, nothing to append).
     */
    private static function lightClose($jobId, $min, $max, $asNew, array &$o)
    {
        $num=self::jobNumber($jobId); $min=max(1,(int)$min); $max=(int)$max;
        $installed=self::contract('FtIndex','installed');
        if ($installed!==true || $max<$min) { return (string)self::contract('FtIndex','bulkEnd',[$num,$min,$max,$asNew]); }
        $n=self::publishedIn($min,$max,self::LIGHT_QUEUE_MAX+1);
        if ($n<=self::LIGHT_APPEND_MAX) { return (string)self::contract('FtIndex','bulkEnd',[$num,$min,$max,$asNew]); }
        $state=self::contract('FtIndex','state',[true]); $docs=is_array($state) ? (int)($state['docs'] ?? 0) : 0;
        $capped=$n>self::LIGHT_QUEUE_MAX;
        if (!$asNew || (!$capped && $n>self::APPEND_DOC_RATIO*$docs) || ($capped && self::APPEND_DOC_RATIO*$docs<=self::LIGHT_QUEUE_MAX)) {
            self::contract('FtIndex','markRebuild',['bulk']); self::closeWindowOnly($num,$min);
            return 'rebuild_due';
        }
        if (self::cliRunnerSeen()) {
            $list=(array)($o['_bulk_append'] ?? []); $list[]=[$min,$max,$asNew ? 1 : 0,time()]; $o['_bulk_append']=array_values($list);
            self::closeWindowOnly($num,$min);
            return 'deferred';
        }
        return self::queueRange($num,$min,$max,$n);
    }
    /** R2-7b: vod_ft_state.bulk_job = 0 without an append: C1 bulkEnd over the empty range [$min, $min - 1]. */
    private static function closeWindowOnly($num, $min)
    {
        self::contract('FtIndex','bulkEnd',[(int)$num,max(1,(int)$min),max(1,(int)$min)-1,false]);
    }
    /** Published, not recycled videos with vod_id in [$min, $max] along the primary key, counted up to $cap. */
    private static function publishedIn($min, $max, $cap)
    {
        if ((int)$max<(int)$min) { return 0; }
        $t=(string)config('database.prefix').'vod';
        try { $r=Db::query('SELECT COUNT(*) AS n FROM (SELECT vod_id FROM `'.$t.'` FORCE INDEX (PRIMARY) WHERE vod_id >= ? AND vod_id <= ? AND vod_status = 1 AND vod_recycle_time = 0 LIMIT '.max(1,(int)$cap).') x',[(int)$min,(int)$max]); return (int)($r[0]['n'] ?? 0); }
        catch (\Throwable $e) { return (int)$cap; }
    }
    /** R2-7b: queue the published videos of [$min, $max] for the budgeted drain (C1 touch), then close the window; above LIGHT_QUEUE_MAX rebuild_due. */
    private static function queueRange($num, $min, $max, $n)
    {
        if ((int)$n>self::LIGHT_QUEUE_MAX) { self::contract('FtIndex','markRebuild',['bulk']); self::closeWindowOnly($num,$min); return 'rebuild_due'; }
        $t=(string)config('database.prefix').'vod';
        for ($a=(int)$min; $a<=(int)$max; $a+=50000) {
            $z=min((int)$max,$a+49999);
            $ids=array_map('intval',array_column(Db::query('SELECT vod_id FROM `'.$t.'` FORCE INDEX (PRIMARY) WHERE vod_id >= ? AND vod_id <= ? AND vod_status = 1 AND vod_recycle_time = 0',[$a,$z]),'vod_id'));
            if ($ids) { self::contract('FtIndex','touch',[$ids]); }
        }
        self::closeWindowOnly($num,$min);
        return 'queued';
    }
    /** A command-line runner (tools/import.php --run / --tick) wrote its heartbeat in the last 24 h. */
    private static function cliRunnerSeen()
    {
        try { $at=(int)ProviderPacer::cliSeenAt(); } catch (\Throwable $e) { return false; }
        return $at>0 && time()-$at<=86400;
    }
    private static function validRange($r)
    {
        return is_array($r) && count($r)>=3 && is_numeric($r[0]) && is_numeric($r[1]) && (int)$r[0]>=1 && (int)$r[1]>=(int)$r[0]-1;
    }
    /**
     * Optimistic read-modify-write of a job's options: $fn(array $options) returns the new options or null (no change); the
     * write only succeeds while the stored options are unchanged, else it re-reads (a running step writes its options
     * often). Returns the written options, or null when nothing was written.
     */
    private static function patchJobOptions($jobId, callable $fn, $tries = 10)
    {
        for ($i=0; $i<$tries; $i++) {
            $row=Db::name('avdb_import_job')->where('job_id',(string)$jobId)->field('options')->find();
            if (!$row) { return null; }
            $old=(string)$row['options']; $o=json_decode($old,true); $o=is_array($o) ? $o : [];
            $new=$fn($o); if (!is_array($new)) { return null; }
            $json=json_encode($new,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if ($json===$old) { return $new; }
            if (Db::name('avdb_import_job')->where('job_id',(string)$jobId)->where('options',$old)->update(['options'=>$json])) { return $new; }
            usleep(20000);
        }
        return null;
    }
    /**
     * R2-7b: run the sorted appends light closes handed to the command line (job option _bulk_append), oldest job first: C1
     * bulkEnd over each recorded range (resumable after a kill: lane S keeps the position in runtime/ft/bulkend.json). The
     * ranges move to _bulk_appending before the append and leave it after (a killed run leaves them for the next run). A
     * light caller ($light, every request except the command line) only queues ranges the command line left for more than
     * DEFER_MAX_AGE (cron stopped) for the budgeted drain. Jobs that run or hold a step lease and $skipJob wait. One runner
     * per site (GET_LOCK). Returns the ranges done.
     */
    public static function runDeferredAppends($light = null, $skipJob = '')
    {
        $light=$light===null ? PHP_SAPI!=='cli' : (bool)$light;
        try { $rows=Db::name('avdb_import_job')->where('options','like','%"_bulk_append%')->field('job_id,state,lock_until')->order('updated_at asc')->limit(20)->select(); }
        catch (\Throwable $e) { return 0; }
        if (!$rows) { return 0; }
        $lock=(string)config('database.database').'_bulkappend';
        try { $got=Db::query('SELECT GET_LOCK(?, 0) AS l',[$lock]); } catch (\Throwable $e) { return 0; }
        if ((string)($got[0]['l'] ?? '')!=='1') { return 0; }
        $done=0;
        try {
            foreach ($rows as $row) {
                if ((string)$row['job_id']===(string)$skipJob || in_array($row['state'],self::RUN_STATES,true) || (int)$row['lock_until']>time()) { continue; }
                $due=[];
                $claimed=self::patchJobOptions($row['job_id'],function (array $f) use ($light,&$due) {
                    $due=[]; $keep=[];
                    foreach (array_merge((array)($f['_bulk_appending'] ?? []),(array)($f['_bulk_append'] ?? [])) as $r) {
                        if (!self::validRange($r)) { continue; }
                        if (!$light || time()-(int)($r[3] ?? 0)>=self::DEFER_MAX_AGE) { $due[]=$r; } else { $keep[]=$r; }
                    }
                    if (!$due) { return null; }
                    $f['_bulk_appending']=$due; if ($keep) { $f['_bulk_append']=$keep; } else { unset($f['_bulk_append']); }
                    return $f;
                });
                if ($claimed===null || !$due) { continue; }
                $last='';
                foreach ($due as $r) {
                    $num=self::jobNumber($row['job_id']);
                    $res=$light ? self::queueRange($num,(int)$r[0],(int)$r[1],self::publishedIn((int)$r[0],(int)$r[1],self::LIGHT_QUEUE_MAX+1)) : self::contract('FtIndex','bulkEnd',[$num,(int)$r[0],(int)$r[1],!empty($r[2])]);
                    $last=is_string($res) ? $res : ''; $done++;
                }
                self::patchJobOptions($row['job_id'],function (array $f) use ($last) { unset($f['_bulk_appending']); $f['_bulk_end']=$last; $f['_bulk_deferred_at']=time(); return $f; });
                self::trace('deferred_append:'.count($due).':'.$last);
            }
        } finally { try { Db::query('SELECT RELEASE_LOCK(?) AS r',[$lock]); } catch (\Throwable $e) { /* connection gone */ } }
        return $done;
    }

    // ------------------------------------------------------------------ FULLTEXT filter keys of large bulk jobs (P-O1)

    /**
     * P-O1 (owner decisions 2026-10-17): the four FULLTEXT keys of the actor / tag / category / director filters during
     * large bulk jobs. Measured (BUILD-P "owner items 2026-10-17"; MariaDB 11.4, 7.1-7.9 M videos, 2 cores, 512 MB pool):
     * the four keys cost 0.12 ms per inserted video at the database (0.417 -> 0.536 ms per row in 50-row transactions),
     * about 4 % of a bulk import's 2.8 ms per video (end-to-end 150,000-video jobs: 332 / 356 videos/s with them,
     * 298 / 362 / 357 without: no difference outside the noise), while re-adding them costs 229-476 s at 7.1-7.9 M
     * videos and the site's filter pages lose their fast path for the whole job. A bulk job is NOT much faster without
     * them, so they stay by default; the drop is opt-in (job option drop_indexes = 1, tools/import.php --drop-indexes=1)
     * and even then happens only for a job that writes more than FT_DROP_MIN further videos and at least FT_DROP_SHARE
     * of the table (a fresh whole-catalog import: about 13 minutes saved, minus a 4-7 minute re-add at 6.6 M).
     * Dropped keys are re-added when no running or recently paused bulk job holds them any more (job end, cancel, undo,
     * remove-all, a pause longer than BULK_IDLE_CLOSE, a crashed runner, pauseAll), one ALTER per key, so a crash loses at
     * most the key being built. ft_vod_name (keyword search) always stays: it keeps the hidden FTS_DOC_ID column, so a
     * re-add builds only that key and never copies the table. State lives in {pre}avdb_import_ftkey (holder rows
     * 'job:<id>' and one row per dropped key, written BEFORE the ALTER), never only in a file or in job options.
     */
    public const FT_FILTER_KEYS = ['ft_vod_actor' => 'vod_actor', 'ft_vod_tag' => 'vod_tag', 'ft_vod_class' => 'vod_class', 'ft_vod_director' => 'vod_director'];
    public const FT_KEEP_KEY = 'ft_vod_name';
    /** Opt-in drop only when the job is expected to write more than this many further videos (owner: bulk jobs > 100,000 rows). */
    public const FT_DROP_MIN = 100000;
    /** ... and only after it wrote / queued this many new videos with at least FT_DROP_NEW_RATIO of the screened rows new
     *  (a re-run that only hash-skips never drops the keys). */
    public const FT_DROP_SEEN = 5000;
    public const FT_DROP_NEW_RATIO = 0.5;
    /**
     * ... and only when those further writes are at least FT_DROP_SHARE of the table after the job: the saving (0.12 ms
     * per written video) then exceeds the re-add (32-60 microseconds per table row). Measured counter-example: an opted-in
     * 150,000-video job into 7.7 M videos took 881 s (451 s apply + 425 s re-add) against 449 s with the keys kept.
     */
    public const FT_DROP_SHARE = 0.5;
    /** Decision re-checked at most every FT_RECHECK_S seconds per job and process. */
    public const FT_RECHECK_S = 60;
    /** lock_wait_timeout (s) of the DROP / ADD: a long query on the table makes the ALTER give up (retried later)
     *  instead of queueing every page behind its metadata lock. */
    public const FT_LOCK_WAIT = 5;
    /** Web callers start another re-add only while the call has run less than this many seconds (CLI: no bound). */
    public const FT_WEB_BUDGET = 20;
    /** Per process: job id => [checked_at, decision]. */
    private static $ftChecked = [];

    /** P-O1 (pure): videos a job screens in all (0 = unknown, e.g. a whole catalog before its size is known). */
    public static function ftExpected(array $o)
    {
        if ((int)($o['max_items'] ?? 0) > 0) { return (int)$o['max_items']; }
        if ((int)($o['size_bound'] ?? 0) > 0) { return (int)floor((int)$o['size_bound'] / self::SIZE_BOUND); }
        $n = 0;
        if (!empty($o['snap']['shards']) && is_array($o['snap']['shards'])) { foreach ($o['snap']['shards'] as $sh) { $n += is_array($sh) ? (int)($sh[3] ?? ($sh['rows'] ?? 0)) : 0; } }
        return $n;
    }
    /**
     * P-O1 (pure): '' = drop the filter keys now, else the reason not to. $c: 'new' = videos created, updated, failed or
     * queued so far; 'screened' = new + skipped + hash-skipped + tombstone-skipped + filtered rows. Expected further
     * writes = (expected - screened) x new share; 'table_rows' = videos in the table now; 'remote_total' = the provider's
     * catalog size when the job's own size is unknown (unknown both: counts as large).
     */
    public static function ftDropDecision(array $o, array $c)
    {
        if (empty($o['bulk'])) { return 'not_bulk'; }
        if (($o['type'] ?? 'import') !== 'import' || in_array($o['mode'] ?? '', ['presence', 'incremental', 'feed', 'selected'], true)) { return 'not_import'; }
        if (empty($o['drop_indexes'])) { return 'kept'; }
        $new = max(0, (int)($c['new'] ?? 0)); $screened = max($new, (int)($c['screened'] ?? 0));
        if ($new < self::FT_DROP_SEEN) { return 'few_rows'; }
        $ratio = $new / max(1, $screened);
        if ($ratio < self::FT_DROP_NEW_RATIO) { return 'mostly_unchanged'; }
        $expected = self::ftExpected($o);
        if ($expected <= 0) { $expected = max(0, (int)($c['remote_total'] ?? 0)); }
        if ($expected > 0) {
            $further = ($expected - $screened) * $ratio;
            if ($further <= self::FT_DROP_MIN) { return 'small'; }
            if ($further < self::FT_DROP_SHARE * (max(0, (int)($c['table_rows'] ?? 0)) + $further)) { return 'share'; }
        }
        return '';
    }
    /** P-O1 (pure): a holder job keeps the keys dropped while it holds a step lease, or runs / is paused and was touched
     *  less than BULK_IDLE_CLOSE seconds ago (a crashed runner or a long pause gives the keys back). */
    public static function ftHolderAlive($job, $now)
    {
        if (!is_array($job)) { return false; }
        if ((int)($job['lock_until'] ?? 0) > $now) { return true; }
        $st = (string)($job['state'] ?? '');
        return (in_array($st, self::RUN_STATES, true) || in_array($st, self::PAUSE_STATES, true)) && (int)($job['updated_at'] ?? 0) > $now - self::BULK_IDLE_CLOSE;
    }
    /** P-O1 (pure): ALTER statements. */
    public static function ftDropSql($table, array $keys)
    {
        return 'ALTER TABLE `' . $table . '` ' . implode(', ', array_map(function ($k) { return 'DROP INDEX `' . $k . '`'; }, $keys));
    }
    public static function ftAddSql($table, $key, $col)
    {
        return 'ALTER TABLE `' . $table . '` ADD FULLTEXT KEY `' . $key . '` (`' . $col . '`)';
    }
    private static function ftTable() { return (string)config('database.prefix') . 'avdb_import_ftkey'; }
    private static function ftVodTable() { return (string)config('database.prefix') . 'vod'; }
    /** FULLTEXT keys of the vod table now: name => comma list of its columns (lower case). */
    private static function ftKeysNow()
    {
        $keys = [];
        foreach (Db::query("SELECT index_name AS k, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS c FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_type = 'FULLTEXT' GROUP BY index_name", [self::ftVodTable()]) as $r) {
            $r = array_change_key_case($r, CASE_LOWER); $keys[strtolower((string)$r['k'])] = strtolower((string)$r['c']);
        }
        return $keys;
    }
    private static function ftLock()
    {
        $name = 'javcms_ftkeys_' . substr(hash('sha256', (string)config('database.database') . '|' . (string)config('database.prefix')), 0, 32);
        try { $r = Db::query('SELECT GET_LOCK(?, 0) AS l', [$name]); } catch (\Throwable $e) { return null; }
        return isset($r[0]['l']) && (string)$r[0]['l'] === '1' ? $name : null;
    }
    private static function ftUnlock($name)
    {
        if ($name) { try { Db::query('SELECT RELEASE_LOCK(?) AS r', [$name]); } catch (\Throwable $e) { /* connection gone: the lock went with it */ } }
    }
    /**
     * One ALTER with a short metadata-lock wait (FT_LOCK_WAIT) and without the web statement limit (MariaDB's
     * max_statement_time also stops DDL: the admin entrance's 30 s limit would abort every re-add of a large table);
     * both session values are restored afterwards.
     */
    private static function ftAlter($sql)
    {
        $old = 0; $oldStmt = null;
        try { $r = Db::query('SELECT @@SESSION.lock_wait_timeout AS w'); $old = (int)($r[0]['w'] ?? 0); Db::execute('SET SESSION lock_wait_timeout = ' . (int)self::FT_LOCK_WAIT); } catch (\Throwable $e) { $old = 0; }
        try { $r = Db::query('SELECT @@SESSION.max_statement_time AS m'); $m = (float)($r[0]['m'] ?? 0); if ($m > 0) { Db::execute('SET SESSION max_statement_time = 0'); $oldStmt = $m; } } catch (\Throwable $e) { $oldStmt = null; /* MySQL: max_execution_time limits SELECT only */ }
        if (PHP_SAPI !== 'cli') { @ignore_user_abort(true); if (function_exists('set_time_limit')) { @set_time_limit(0); } }
        try { Db::execute($sql); }
        finally {
            if ($old > 0) { try { Db::execute('SET SESSION lock_wait_timeout = ' . $old); } catch (\Throwable $e) { /* next connection starts with the server value */ } }
            if ($oldStmt !== null) { try { Db::execute('SET SESSION max_statement_time = ' . sprintf('%.3F', $oldStmt)); } catch (\Throwable $e) { /* idem */ } }
        }
    }
    /** Rows of the state table ([] when it does not exist yet). */
    private static function ftRows()
    {
        try { $rows = Db::query('SELECT key_name, col, job_id, state, tries, message, updated_at FROM `' . self::ftTable() . '`'); } catch (\Throwable $e) { return []; }
        return array_map(function ($r) { return array_change_key_case($r, CASE_LOWER); }, $rows);
    }
    /** Videos written or queued so far and rows screened so far (ftDropDecision input). */
    private function ftCounts($jobId, array $o)
    {
        $new = 0;
        foreach (Db::name('avdb_import_item')->where('job_id', $jobId)->field('state,count(*) n')->group('state')->select() as $r) { if (in_array($r['state'], ['created', 'updated', 'failed', 'pending'], true)) { $new += (int)$r['n']; } }
        $bc = (array)($o['_bulk_counts'] ?? []);
        $new += (int)($bc['created'] ?? 0);
        $screened = $new + (int)($bc['skipped'] ?? 0) + (int)($o['_hash_skipped'] ?? 0) + (int)($o['_tomb_skipped'] ?? 0) + (int)($o['_filtered'] ?? 0);
        $rows = 0;
        try { $t = Db::query('SELECT table_rows AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [self::ftVodTable()]); $rows = $t ? (int)(array_change_key_case($t[0], CASE_LOWER)['n'] ?? 0) : 0; } catch (\Throwable $e) { $rows = 0; }
        $totals = self::providerTotals((string)($o['provider'] ?? self::PROVIDER));
        return ['new' => $new, 'screened' => $screened, 'table_rows' => $rows, 'remote_total' => (int)($totals[0] ?? 0)];
    }
    /** P-O1: before a bulk batch: drop the filter keys when ftDropDecision() says so (checked at most every FT_RECHECK_S). */
    private function ftKeysBeforeBatch($j, array $o)
    {
        if (empty($o['bulk'])) { return; }
        $id = (string)$j['job_id']; $now = time();
        $seen = self::$ftChecked[$id] ?? null;
        // 'few_rows' is re-checked at every batch (the drop should come right after the first FT_DROP_SEEN videos)
        if ($seen && ($seen[1] === 'held' || ($seen[1] !== 'few_rows' && $now - $seen[0] < self::FT_RECHECK_S))) { return; }
        try {
            $why = self::ftDropDecision($o, $this->ftCounts($id, $o));
            if ($why === '') { $why = self::ftDrop($id); }
            self::$ftChecked[$id] = [$now, $why];
            self::trace('ft_keys:' . $why);
        } catch (\Throwable $e) {
            self::$ftChecked[$id] = [$now, 'error'];
            error_log('[AVDB import] FULLTEXT filter keys: ' . get_class($e) . ': ' . $e->getMessage());
        }
    }
    /**
     * P-O1: record job $jobId as a holder and drop the filter keys that exist as one-column FULLTEXT keys on their
     * column (InnoDB only, and only while ft_vod_name stays, so a re-add never rebuilds the table). The holder row and
     * one row per key are written BEFORE the ALTER: a crash at any point leaves a record that ftKeysRestore() finishes.
     * Returns 'held' (dropped now or already dropped by another holder), or why nothing was dropped.
     */
    public static function ftDrop($jobId)
    {
        $lock = self::ftLock(); if (!$lock) { return 'busy'; }
        try {
            $engine = Db::query('SELECT engine AS e FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [self::ftVodTable()]);
            $engine = $engine ? strtolower((string)(array_change_key_case($engine[0], CASE_LOWER)['e'] ?? '')) : '';
            if ($engine !== 'innodb') { return 'engine'; }
            $now = self::ftKeysNow();
            if (($now[self::FT_KEEP_KEY] ?? '') !== 'vod_name') { return 'no_name_key'; }
            $drop = []; foreach (self::FT_FILTER_KEYS as $k => $col) { if (($now[$k] ?? '') === $col) { $drop[$k] = $col; } }
            $rows = self::ftRows(); $keyRows = array_filter($rows, function ($r) { return strpos((string)$r['key_name'], 'job:') !== 0; });
            if (!$drop && !$keyRows) { return 'none'; }
            $t = time(); $table = self::ftTable();
            Db::execute('INSERT IGNORE INTO `' . $table . '` (key_name, col, job_id, state, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', ['job:' . $jobId, '', (string)$jobId, 'hold', $t, $t]);
            if (!$drop) { return 'held'; }
            foreach ($drop as $k => $col) { Db::execute('INSERT INTO `' . $table . '` (key_name, col, job_id, state, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE state = VALUES(state), updated_at = VALUES(updated_at), message = \'\'', [$k, $col, (string)$jobId, 'dropped', $t, $t]); }
            $t0 = microtime(true);
            self::ftAlter(self::ftDropSql(self::ftVodTable(), array_keys($drop)));
            self::trace('ft_keys_dropped:' . implode(',', array_keys($drop)) . ':' . round(microtime(true) - $t0, 3));
            self::contract('FilterMatch', 'forget');
            return 'held';
        } finally { self::ftUnlock($lock); }
    }
    /**
     * P-O1: re-add the filter keys once no holder job is alive (ftHolderAlive; $force: every holder counts as gone, for
     * pauseAll). One ALTER per key (each finished key stays: resumable after a crash, a time-out or a cancel); $budget =
     * seconds after which no further key is started (0 = all, the CLI). Single flight (GET_LOCK), never throws; a failed
     * ALTER (metadata-lock wait, full disk) keeps its row and the next call retries. Returns
     * ['restored' => keys, 'pending' => keys, 'held' => bool, 'seconds' => [key => s], 'error' => ''].
     */
    public static function ftKeysRestore($budget = 0, $force = false)
    {
        $out = ['restored' => [], 'pending' => [], 'held' => false, 'seconds' => [], 'error' => ''];
        $rows = self::ftRows(); if (!$rows) { return $out; }
        $lock = self::ftLock(); if (!$lock) { $out['error'] = 'busy'; return $out; }
        try {
            $now = time(); $keys = []; $table = self::ftTable();
            foreach (self::ftRows() as $r) {
                if (strpos((string)$r['key_name'], 'job:') !== 0) { $keys[(string)$r['key_name']] = (string)$r['col']; continue; }
                $job = Db::name('avdb_import_job')->where('job_id', (string)$r['job_id'])->field('state,updated_at,lock_until')->find();
                if (!$force && self::ftHolderAlive($job ?: null, $now)) { $out['held'] = true; continue; }
                Db::execute('DELETE FROM `' . $table . '` WHERE key_name = ?', [(string)$r['key_name']]);
            }
            if ($out['held']) { $out['pending'] = array_keys($keys); return $out; }
            if (!$keys) { return $out; }
            $present = self::ftKeysNow(); $t0 = microtime(true);
            foreach ($keys as $k => $col) {
                if ((self::FT_FILTER_KEYS[$k] ?? '') !== $col) { Db::execute('DELETE FROM `' . $table . '` WHERE key_name = ?', [$k]); continue; }
                if (!isset($present[$k])) {
                    if ($budget > 0 && $out['restored'] && microtime(true) - $t0 >= $budget) { $out['pending'][] = $k; continue; }
                    Db::execute('UPDATE `' . $table . '` SET state = ?, tries = tries + 1, updated_at = ? WHERE key_name = ?', ['adding', time(), $k]);
                    $t1 = microtime(true);
                    try { self::ftAlter(self::ftAddSql(self::ftVodTable(), $k, $col)); }
                    catch (\Throwable $e) {
                        if (!preg_match('/\b1061\b|Duplicate key name/i', $e->getMessage())) {
                            if (StorageGuard::isStorageError($e)) { StorageGuard::trip($e); }
                            $msg = mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 250, 'UTF-8');
                            Db::execute('UPDATE `' . $table . '` SET state = ?, message = ?, updated_at = ? WHERE key_name = ?', ['dropped', $msg, time(), $k]);
                            error_log('[AVDB import] re-adding ' . $k . ': ' . $msg);
                            $out['error'] = $msg; $out['pending'][] = $k;
                            break;
                        }
                    }
                    $out['restored'][] = $k; $out['seconds'][$k] = round(microtime(true) - $t1, 3);
                }
                Db::execute('DELETE FROM `' . $table . '` WHERE key_name = ?', [$k]);
            }
            if ($out['restored']) { self::trace('ft_keys_restored:' . implode(',', $out['restored'])); self::contract('FilterMatch', 'forget'); }
            foreach (array_keys($keys) as $k) { if (!in_array($k, $out['restored'], true) && !in_array($k, $out['pending'], true) && $out['error'] !== '') { $out['pending'][] = $k; } }
        } catch (\Throwable $e) {
            $out['error'] = mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 250, 'UTF-8');
            error_log('[AVDB import] FULLTEXT filter keys: ' . $out['error']);
        } finally { self::ftUnlock($lock); }
        return $out;
    }
    /** P-O1: job $jobId stops holding the drop (finished, cancelled, undone, removed); re-adds what no other job holds. */
    public static function ftKeysRelease($jobId, $budget = null)
    {
        try { Db::execute('DELETE FROM `' . self::ftTable() . '` WHERE key_name = ?', ['job:' . (string)$jobId]); } catch (\Throwable $e) { return null; }
        unset(self::$ftChecked[(string)$jobId]);
        return self::ftKeysRestore($budget === null ? (PHP_SAPI === 'cli' ? 0 : self::FT_WEB_BUDGET) : $budget);
    }
    /** P-O1 status: ['state' => '' | 'dropped' | 'adding', 'keys' => dropped key names, 'held_by' => job ids, 'message' => last error]. */
    public static function ftKeysState()
    {
        $s = ['state' => '', 'keys' => [], 'held_by' => [], 'message' => ''];
        foreach (self::ftRows() as $r) {
            if (strpos((string)$r['key_name'], 'job:') === 0) { $s['held_by'][] = (string)$r['job_id']; continue; }
            $s['keys'][] = (string)$r['key_name'];
            if ($r['state'] === 'adding') { $s['state'] = 'adding'; } elseif ($s['state'] === '') { $s['state'] = 'dropped'; }
            if ((string)$r['message'] !== '') { $s['message'] = (string)$r['message']; }
        }
        return $s;
    }

    // ------------------------------------------------------------------ discovery

    private function discover($j,$o,$token)
    {
        $t0=microtime(true);
        $provider=self::jobProvider($j); $caps=$this->caps($provider);
        $legacy=($caps['stable_cursor'] ?? '')==='export' && in_array($o['mode'],['selected','pages','all'],true);
        if (!$legacy) { $this->discoverScope($j,$o,$token,$provider,$caps); return; }
        $count=Db::name('avdb_import_item')->where('job_id',$j['job_id'])->count(); $remaining=(int)$o['max_items']===0 && $o['mode']==='all' ? 1000 : $o['max_items']-$count;
        if ($remaining<=0) { Db::name('avdb_import_job')->where(['job_id'=>$j['job_id'],'lock_token'=>$token])->update(['discovery_done'=>1]); return; }
        $f=$o['filters']; $f['ac']='detail'; $rows=[]; $p=$this->prov($provider);
        if ($o['mode']==='selected') {
            $ids=array_slice($o['ids'],(int)$j['cursor'],min(1000,$remaining,max(1,(int)($o['_selected_limit'] ?? 1000))));
            if (!$ids) { Db::name('avdb_import_job')->where(['job_id'=>$j['job_id'],'lock_token'=>$token])->update(['discovery_done'=>1]); return; }
            $f=['ac'=>'detail','ids'=>implode(',',$ids)];
            try { $r=$p->paced(function () use ($f) { return $this->client->fetchImport($f,'en'); }); }
            catch (\Throwable $e) {
                if (count($ids)<=1 || ((int)$e->getCode()!==414 && strpos($e->getMessage(),'(HTTP 414)')===false)) { throw $e; }
                $o['_selected_limit']=min(500,max(1,intdiv(count($ids),2)));
                $this->checkpoint($j['job_id'],$token,['options'=>$this->json($o)]);
                return;
            }
            $rows=$r['list'] ?? [];
            $limit=max(1,(int)($r['reported_limit'] ?? $r['limit'] ?? 1000));
            // Save the provider's clamp as a durable discovery checkpoint. Retry
            // smaller ID cohorts; never fail IDs hidden by a truncated response.
            if ($limit<count($ids)) {
                $o['_selected_limit']=$limit;
                $this->checkpoint($j['job_id'],$token,['options'=>$this->json($o)]);
                return;
            }
        } elseif (array_key_exists('export_cursor',$o)) {
            if (!self::fullExportOptions($o)) { throw new \RuntimeException('Export checkpoint does not match this import scope.'); }
            $r=$p->paced(function () use ($o) { return $this->client->fetchExport(['cursor'=>(int)$o['export_cursor'],'upper_id'=>(int)$o['export_upper']]); }); $rows=$r['list'];
            $upper=(int)$r['upper_id']; $next=(int)$r['next_cursor']; $cursor=(int)$o['export_cursor'];
            if ($upper<0 || ((int)$o['export_upper']!==0 && $upper!==(int)$o['export_upper']) || $next<0 || (!empty($rows) && ($next<1 || ($cursor!==0 && $next>=$cursor) || $next>$upper)) || (empty($rows) && !$r['exhausted'])) { throw new \RuntimeException('AVDB export cursor did not advance within its fixed bound.'); }
            $seenExport=[];
            foreach ($rows as $row) { $remote=(int)$row['vod_id']; if ($remote<1 || $remote>$upper || ($cursor!==0 && $remote>=$cursor) || isset($seenExport[$remote])) { throw new \RuntimeException('AVDB export returned an out-of-bound or duplicate video identity.'); } $seenExport[$remote]=true; }
            if ($rows && min(array_keys($seenExport))!==$next) { throw new \RuntimeException('AVDB export checkpoint does not match its returned video identities.'); }
        } else { $f['pg']=(int)$j['page']; $f['pagesize']=$o['filters']['pagesize']; $r=$p->paced(function () use ($f) { return ((int)$f['pagesize']===1000 || (isset($f['tag']) && $f['tag']!=='')) ? $this->client->fetchImport($f,'en') : $this->client->fetch($f,'en'); }); $rows=$r['list'] ?? []; }
        $rankBase=array_key_exists('export_cursor',$o) ? null : ((int)$j['page']-1)*max(1,(int)($o['filters']['pagesize'] ?? 1000));
        Db::startTrans(); try {
            $this->fence($j['job_id'],$token);
            $candidateIds=array_map('intval',array_column($rows,'vod_id')); if ($o['mode']==='selected') { $candidateIds=array_merge($candidateIds,$ids); }
            $seen=$candidateIds ? array_fill_keys(array_map('intval',Db::name('avdb_import_item')->where(['job_id'=>$j['job_id'],'remote_id'=>['in',array_values(array_unique($candidateIds))]])->column('remote_id')),true) : []; $insert=[]; $returned=[];
            $kept=$this->screenRows($o,$provider,$caps,array_slice($rows,0,$remaining),$rankBase,array_key_exists('export_cursor',$o)?(int)$r['upper_id']:0);
            foreach (array_slice($rows,0,$remaining) as $row) {
                $remote=(int)($row['vod_id'] ?? 0); if ($remote<=0 || ($o['mode']==='selected' && !in_array($remote,$ids,true))) { continue; } $returned[$remote]=true;
                if (isset($seen[$remote])) { continue; } $seen[$remote]=true;
                if (!isset($kept[$remote])) { continue; }
                $insert[]=['job_id'=>$j['job_id'],'remote_id'=>$remote,'vod_id'=>0,'state'=>'pending','payload'=>$this->json($kept[$remote]),'translations'=>'{}','locale_cursor'=>1,'before_image'=>'','after_image'=>'','message'=>''];
            }
            if ($o['mode']==='selected') { foreach ($ids as $missing) { if (!isset($returned[$missing]) && !isset($seen[$missing])) { $insert[]=['job_id'=>$j['job_id'],'remote_id'=>$missing,'vod_id'=>0,'state'=>'failed','payload'=>'{}','translations'=>'{}','locale_cursor'=>1,'before_image'=>'','after_image'=>'','message'=>'Selected video was not returned by the English provider.']; } } }
            if ($insert) { Db::name('avdb_import_item')->insertAll($insert); }
            $done=$o['mode']==='selected' ? ((int)$j['cursor']+count($ids)>=count($o['ids'])) : (array_key_exists('export_cursor',$o)?(bool)$r['exhausted']:(count($rows)<max(1,(int)($r['reported_limit'] ?? $r['limit'] ?? $f['pagesize'])) || ($o['mode']==='pages' && (int)$j['page']>=$o['end_page'])));
            $checkpoint=['page'=>(int)$j['page']+($o['mode']!=='selected'?1:0),'cursor'=>(int)$j['cursor']+($o['mode']==='selected'?count($ids):0),'discovery_done'=>$done?1:0,'phase'=>'apply'];
            if (array_key_exists('export_cursor',$o)) { $o['export_cursor']=(int)$r['next_cursor']; $o['export_upper']=(int)$r['upper_id']; if ($r['total']!==null) { $o['export_total']=(int)$r['total']; } }
            $checkpoint+=$this->anomalyHold($o,$provider,$j['job_id']);
            $el=microtime(true)-$t0; $this->lastDiscSec=$el; $o['_disc_s']=round(isset($o['_disc_s']) ? 0.5*(float)$o['_disc_s']+0.5*$el : $el,3); unset($o['_db_cuts']);
            $o=$this->screenCounters($o); $checkpoint['options']=$this->json($o);
            Db::name('avdb_import_job')->where(['job_id'=>$j['job_id'],'lock_token'=>$token])->update($checkpoint); Db::commit();
        } catch (\Throwable $e) { Db::rollback(); throw $e; }
    }
    /** Counters of the last screenRows() call, folded into the options at the checkpoint. */
    private $screen = ['filtered'=>0,'hash'=>0,'tomb'=>0,'older'=>0,'newest'=>0,'refused'=>[],'changed'=>0,'mapped'=>0];
    private function screenCounters(array $o)
    {
        $o['_filtered']=(int)($o['_filtered'] ?? 0)+$this->screen['filtered'];
        $o['_hash_skipped']=(int)($o['_hash_skipped'] ?? 0)+$this->screen['hash'];
        $o['_tomb_skipped']=(int)($o['_tomb_skipped'] ?? 0)+$this->screen['tomb'];
        if ($this->screen['older']>0) { $o['_older_skipped']=(int)($o['_older_skipped'] ?? 0)+$this->screen['older']; }
        if ($this->screen['newest']>(int)($o['_newest'] ?? 0)) { $o['_newest']=$this->screen['newest']; }
        $this->screen=['filtered'=>0,'hash'=>0,'tomb'=>0,'older'=>0,'newest'=>0,'refused'=>[],'changed'=>0,'mapped'=>0];
        return $o;
    }
    /**
     * Discovery screen for every provider (before rows become items): import filters (xvid defaults: duration < 60 s,
     * category "Unknown", unticked categories, optional SD / source languages), tombstones (D-I9: skipped unless a
     * selected import overrides them), and the hash skip (D-I4: sha1 of the normalized row equal to the map's row_hash
     * with state 0 -> dropped). Returns [remote_id => payload] for the rows to queue; the payload is the provider row
     * plus _row_hash and _rank (D-I12 placement). Hash skip is not used for jobs that keep translations (their English
     * row can be unchanged while a translation changed). Runs inside the discovery transaction (map rows read only).
     * $older (round 4, import-r4): rows of a snapshot shard (or its id range): a row older than the row the video holds
     * (avdb_import_map.row_time) is skipped and counted as 'older' (never a D-I8b change). $noHash: remote ids never dropped by
     * the hash skip (a feed row whose video still has a pending item in the job: the item takes the feed row instead of
     * applying its older payload later).
     */
    private function screenRows(array $o,$provider,array $caps,array $rows,$rankBase,$upper,$older=false,array $noHash=[])
    {
        $out=[]; if (!$rows) { return $out; }
        $p=$this->prov($provider);
        $ids=[]; foreach ($rows as $row) { $ids[]=(int)($row['vod_id'] ?? 0); }
        $maps=[]; foreach (Db::name('avdb_import_map')->where('provider',$provider)->where('remote_id','in',array_values(array_unique($ids)))->field('remote_id,vod_id,state,row_hash,row_time')->select() as $m) { $maps[(int)$m['remote_id']]=$m; }
        $useHash=count((array)($o['locales'] ?? ['en']))<=1;
        $mappedVods=[]; foreach ($maps as $m) { if ((int)$m['vod_id']>0 && (int)$m['state']===0) { $mappedVods[(int)$m['vod_id']]=true; } }
        $vods=[]; if ($mappedVods) { foreach (Db::name('vod')->where('vod_id','in',array_keys($mappedVods))->field('vod_id,vod_name,vod_play_url')->select() as $v) { $vods[(int)$v['vod_id']]=$v; } }
        $skipTypes=array_fill_keys(array_map('intval',(array)($o['skip_types'] ?? [])),true);
        $unknownTypes=[]; if (!empty($o['xfilters']['skip_unknown'])) { foreach ($this->providerTypeNames($provider) as $tid=>$tname) { if (self::nameKey($tname)==='unknown') { $unknownTypes[$tid]=true; } } }
        $langs=array_fill_keys((array)($o['xfilters']['langs'] ?? []),true);
        foreach (array_values($rows) as $i=>$row) {
            $remote=(int)($row['vod_id'] ?? 0); if ($remote<=0) { continue; }
            $map=$maps[$remote] ?? null;
            if ($map && (int)$map['state']===2 && (int)$map['vod_id']===0 && empty($o['override_tombstones'])) { $this->screen['tomb']++; continue; }
            $type=(int)($row['type_id'] ?? 0);
            if ($type && (isset($skipTypes[$type]) || isset($unknownTypes[$type]))) { $this->screen['filtered']++; continue; }
            try { $norm=$p->normalize($row); } catch (\Throwable $e) { $norm=null; }
            if ($norm!==null && isset($o['xfilters'])) {
                $x=$o['xfilters'];
                if (!empty($x['skip_short']) && ($norm['vod_duration'] ?? '')!=='' && self::seconds($norm['vod_duration'])<60) { $this->screen['filtered']++; continue; }
                if (!empty($x['skip_sd']) && ($norm['vod_version'] ?? '')==='SD') { $this->screen['filtered']++; continue; }
                if ($langs && !isset($langs[(string)($norm['_lang_code'] ?? '')])) { $this->screen['filtered']++; continue; }
            }
            // round-4 verify fix (import-r4 snapshot re-import): a row older than the row the video was last written from (the provider's
            // vod_time, kept in avdb_import_map.row_time) never replaces it and is no D-I8b change: a second full job on the same snapshot
            // applied the older shard rows over every change the feed had brought after the snapshot (and paused 6 times on D-I8b)
            if ($older && $norm!==null && $map && (int)$map['state']===0 && (int)$map['vod_id']>0) {
                $rt=self::rowTime($norm);
                if ($rt>0 && (int)($map['row_time'] ?? 0)>$rt) { $this->screen['older']++; continue; }
            }
            // D-I8b: rows of existing videos whose title or embed URL would change (the whole page counts, unchanged rows too).
            if ($norm!==null && $map && (int)$map['state']===0 && isset($vods[(int)$map['vod_id']])) { $this->screen['mapped']++; if (self::visibleChange($norm,$vods[(int)$map['vod_id']])) { $this->screen['changed']++; } }
            $hash=$norm!==null ? sha1(json_encode($norm,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) : '';
            if ($useHash && !isset($noHash[$remote]) && $hash!=='' && $map && (int)$map['state']===0 && (int)$map['vod_id']>0 && hash_equals((string)$map['row_hash'],$hash)) { $this->screen['hash']++; continue; }
            if ($norm!==null && !empty($norm['vod_time']) && is_int($norm['vod_time']) && $norm['vod_time']>$this->screen['newest']) { $this->screen['newest']=$norm['vod_time']; }
            $payload=array_diff_key($row,['_avdb_images'=>true,'_avdb_quality'=>true]);
            $payload['_row_hash']=$hash;
            $payload['_rank']=$upper>0 ? max(0,$upper-$remote) : ($rankBase===null ? $i : $rankBase+$i);
            $out[$remote]=$payload;
        }
        return $out;
    }
    private static function seconds($hms) { $p=array_map('intval',explode(':',(string)$hms)); $s=0; foreach ($p as $v) { $s=$s*60+$v; } return $s; }
    private $typeNames = [];
    private function providerTypeNames($provider)
    {
        if (!isset($this->typeNames[$provider])) { $this->typeNames[$provider]=[]; try { foreach (Db::name('avdb_provider_type')->where('provider',$provider)->field('remote_type_id,remote_name')->select() as $r) { $this->typeNames[$provider][(int)$r['remote_type_id']]=(string)$r['remote_name']; } } catch (\Throwable $e) { /* none */ } }
        return $this->typeNames[$provider];
    }
    /**
     * Discovery of the 1.2.0 scopes. selected: byIds chunks of ids_max. recent <= 100,000: pages of 1,000 newest first
     * (D-I3); recent above 100,000 and windows: id windows of 500 walking down from the upper id found once per run by
     * galloping (D-I2); category: `t=` pages per chosen category (web: first 50,000 of the category); incremental:
     * recent(h) pages until a short page (D-I7). The job stops at estimate x 1.2 videos (se-R11b).
     */
    /**
     * Fairness between runners (D-I6 shares one clock): a command-line runner takes the provider's slots back to back,
     * so a web job of the same provider that waits for the pace would never get one. Before each command-line
     * discovery request: when such a web job waits (retry_at now, page still polling), sleep until its slot has passed
     * (at most 16 s, never past the step deadline) and report true (the caller then skips its prefetch).
     */
    private function yieldToWeb($provider)
    {
        if (PHP_SAPI!=='cli') { return false; }
        // a prefetched window already used its slot: collect it first, yield before the next request
        $p=$this->providers[$provider] ?? null; if ($p && method_exists($p,'hasPrefetch') && $p->hasPrefetch()) { return false; }
        $now=time();
        try { $at=(int)Db::name('avdb_import_job')->where('provider',$provider)->where('state','in',self::RUN_STATES)->where('retry_at','between',[$now-2,$now+15])->where('updated_at','>=',$now-30)->where('options','like','%"runner":"web"%')->max('retry_at'); }
        catch (\Throwable $e) { return false; }
        if ($at<=0) { return false; }
        $wait=min(16.0,$at-microtime(true)+1.5);
        if ($this->stepDeadline>0) { $wait=min($wait,$this->stepDeadline-microtime(true)); }
        if ($wait>0) { usleep((int)round($wait*1000000)); }
        self::trace('yield_web');
        return true;
    }
    private function discoverScope($j,array $o,$token,$provider,array $caps)
    {
        $t0=microtime(true); // round 4 (flood-r4 N17): the page's time feeds the step admission (_disc_s)
        // A2: the storage target is checked before each discovery page (the job completes there)
        if ($this->targetReached($j,$o,$token)) { return; }
        $p=$this->prov($provider);
        $yielded=$this->yieldToWeb($provider);
        $count=(int)Db::name('avdb_import_item')->where('job_id',$j['job_id'])->count()+array_sum(array_map('intval',(array)($o['_bulk_counts'] ?? [])));
        // "Newest N" is N provider rows: rows that are already here unchanged (hash skip), here in a newer version (older, round 4)
        // or deleted by an admin (tombstone) count toward N, so running the same scope again writes nothing and stops where the first run did.
        $skipped=(int)($o['_hash_skipped'] ?? 0)+(int)($o['_tomb_skipped'] ?? 0)+(int)($o['_older_skipped'] ?? 0);
        $count+=$skipped;
        $limit=(int)$o['max_items'];
        $remaining=$limit>0 ? $limit-$count : PHP_INT_MAX;
        $done=function (array $o, array $extra = []) use ($j,$token) { $this->checkpoint($j['job_id'],$token,['discovery_done'=>1,'phase'=>'apply','options'=>$this->json($this->screenCounters($o))]+$extra); };
        if ($remaining<=0) { $done($o); return; }
        $rows=[]; $rankBase=null; $upper=0; $set=['phase'=>'apply']; $finished=false; $selectedIds=[];
        $mode=$o['mode'];
        if ($mode==='recent' && $limit>ImportProviders::PAGE_OFFSET_MAX) { $mode='windows'; }
        // A4 ladder for the whole catalog: xvidexport snapshot -> keyset export API -> id windows (--via=windows: id windows).
        // A rung is left only before the next one started (no rung restarts the catalog from the top).
        $ladder=($mode==='snapshot' || ($mode==='windows' && $o['mode']==='windows' && $limit===0)) && ($o['via'] ?? '')!=='windows' && empty($o['win_upper']);
        if ($mode==='snapshot') { $mode='windows'; }
        $r=null; $rung=''; $snapPhase=(string)($o['snap']['phase'] ?? '');
        if ($o['mode']==='feed') {
            $r=$this->feedStep($j,$o,$token,$p,$provider); $rung='feed';
            if ($r===null) { return; }
        } elseif ($ladder && empty($o['ks']) && (!empty($o['snap']) ? ($o['snap']['phase'] ?? '')!=='off' : ($o['mode']==='snapshot' || $this->useSnapshot($o,$p)))) {
            $r=$this->snapshotStep($j,$o,$token,$p,$provider,$limit); $rung='snapshot';
            if ($r===null) { return; }
            $upper=(int)($o['snap']['max_id'] ?? 0);
        } elseif ($ladder && empty($o['ks']['off'])) {
            $r=$this->keysetStep($j,$o,$token,$p,$provider,$limit); $rung='keyset';
            if ($r===null) { return; }
            $upper=(int)$o['ks']['upper'];
        }
        if ($r!==null) {
            $rows=$r['list']; $finished=!empty($r['finished']);
            if (!empty($r['hold'])) { $set['state']=self::stateFor('paused',$provider); $set['error']=mb_substr((string)$r['hold'],0,1000,'UTF-8'); }
        } elseif ($mode==='selected') {
            $chunk=max(1,min((int)$caps['ids_max'],$remaining));
            $selectedIds=array_slice($o['ids'],(int)$j['cursor'],$chunk);
            if (!$selectedIds) { $done($o); return; }
            $r=$p->byIds($selectedIds); $rows=$r['list']; $rankBase=(int)$j['cursor'];
            $set['cursor']=(int)$j['cursor']+count($selectedIds); $finished=$set['cursor']>=count($o['ids']);
        } elseif ($mode==='windows') {
            if ($ladder || ($o['mode']==='windows' && $limit===0)) { $o['_source']='windows'; }
            if (empty($o['win_upper'])) {
                $total=(int)$p->count([]);
                $found=method_exists($p,'findUpper') ? (int)$p->findUpper($total) : 0;
                if ($found<1) { $done($o); return; }
                $o['win_upper']=$found; $o['win_cursor']=0; $o['remote_total']=$total; if ($total>0) { self::storeTotal($provider,0,$total); }
                if (empty($o['size_bound']) && $total>0) { $o['size_bound']=(int)ceil(self::SIZE_BOUND*($limit>0 ? min($limit,$total) : $total)); }
                $this->checkpoint($j['job_id'],$token,['options'=>$this->json($o)]);
                return;
            }
            $r=$p->cursorPage((int)$o['win_cursor'],(int)$o['win_upper']); $rows=$r['list']; $upper=(int)$o['win_upper'];
            $o['win_cursor']=(int)$r['next_cursor']; $finished=!empty($r['exhausted']);
            $o['_windows']=(int)($o['_windows'] ?? 0)+1;
        } elseif ($mode==='recent' || $mode==='incremental' || $mode==='category') {
            $pg=max(1,(int)$j['page']);
            if ($mode==='category') {
                $ci=(int)($o['_cat_i'] ?? 0); $cats=array_values((array)$o['categories']);
                if (!isset($cats[$ci])) { $done($o); return; }
                $r=$p->page(['t'=>(int)$cats[$ci]],$pg);
            } elseif ($mode==='incremental') { $r=$p->recent((int)$o['hours'],$pg); }
            else { $r=$p->page([],$pg); }
            $rows=$r['list']; $size=max(1,(int)($r['limit'] ?? 0) ?: 1000); $rankBase=($pg-1)*$size;
            $boundKey=$mode==='category' ? 'c'.(int)($o['_cat_i'] ?? 0) : 'p';
            if ($pg===1 && isset($r['total']) && ($o['_bound_for'] ?? '')!==$boundKey) {
                $tot=(int)$r['total']; $o['remote_total']=(int)($o['remote_total'] ?? 0)+$tot; $o['_bound_for']=$boundKey;
                if ($mode==='category') { self::storeTotal($provider,(int)$cats[$ci],$tot); } elseif ($mode==='recent') { self::storeTotal($provider,0,$tot); }
                $est=$limit>0 ? min($limit,$tot) : $tot; if ($mode!=='incremental' && $est>0) { $o['size_bound']=(int)($o['size_bound'] ?? 0)+(int)ceil(self::SIZE_BOUND*$est); }
                if (!empty($o['_bulk_pending']) && $tot>self::BULK_MIN) { $o['bulk']=1; unset($o['_bulk_pending']); $o['_vod_min']=(int)Db::name('vod')->max('vod_id')+1; self::contract('FtIndex','bulkBegin',[self::jobNumber($j['job_id']),(int)$o['_vod_min']]); }
            }
            $short=count($r['list'])+count((array)($r['rejected'] ?? []))+(int)($r['ignored'] ?? 0)<$size || ($mode==='recent' && $pg*$size>=ImportProviders::PAGE_OFFSET_MAX);
            if ($mode==='category' && $short) { $o['_cat_i']=(int)($o['_cat_i'] ?? 0)+1; $set['page']=1; $finished=!isset(array_values((array)$o['categories'])[$o['_cat_i']]); }
            else { $set['page']=$pg+1; $finished=$short; }
        } else { throw new \RuntimeException('Unsupported import scope.'); }
        $feedPage=$rung==='feed' || ($rung==='snapshot' && $snapPhase==='changes');
        Db::startTrans(); try {
            $this->fence($j['job_id'],$token);
            $ids=array_map('intval',array_column($rows,'vod_id'));
            $existing=[];
            if ($ids) { foreach (Db::name('avdb_import_item')->where(['job_id'=>$j['job_id'],'remote_id'=>['in',array_values(array_unique($ids))]])->field('item_id,remote_id,state')->select() as $it) { $existing[(int)$it['remote_id']]=$it; } }
            $seen=array_fill_keys(array_keys($existing),true);
            // round-4 verify fix (import-r4 snapshot re-import feed loss): on a change-feed page a row whose video already has an item in
            // this job replaces that item's payload and the item is applied again, last. Before: every such row was dropped (UNIQUE
            // job_remote), and the stored feed cursor was past it, so the hourly feed never brought it back (0 of 1,003 changes left)
            $requeue=[];
            if ($feedPage) { foreach ($rows as $row) { $rid=(int)$row['vod_id']; if (isset($existing[$rid]) && in_array((string)$existing[$rid]['state'],self::REQUEUE_STATES,true)) { $requeue[$rid]=$existing[$rid]; } } }
            $rows=array_values(array_filter($rows,function ($row) use ($seen,$requeue) { $rid=(int)$row['vod_id']; return !isset($seen[$rid]) || isset($requeue[$rid]); }));
            // round 4: the 'older' rule for snapshot shard pages (a snapshot is older than every change applied after it was written)
            $kept=$this->screenRows($o,$provider,$caps,$rows,$rankBase,$upper,$rung==='snapshot' && !$feedPage,array_filter($requeue,function ($it) { return (string)$it['state']==='pending'; }));
            $pageSkipped=(int)$this->screen['hash']+(int)$this->screen['tomb']+(int)$this->screen['older'];
            if (count($kept)+$pageSkipped>=$remaining && $limit>0) { $kept=array_slice($kept,0,max(0,$remaining-$pageSkipped),true); $finished=true; }
            // se-R11b: never queue more than 1.2 x the estimate from the catalog listing; the job stops there. Round-3 verify fix
            // (import-r3 snapshot feed vs size bound): the bound counts the rows the job queued from the listing (snapshot shards,
            // keyset / id-window / page listings: _bound_n); change-feed pages (snapshot 'changes' phase, hourly feed mode) are
            // guarded by feedAnomalyHold() alone and hash- / tombstone-skipped rows write nothing, so neither counts (a feed above
            // ~20 % of the snapshot's rows paused every snapshot job at the bound, and its resume was refused)
            // import-r2 N1 / N3 / N6: change-feed pages are measured against the mapped catalog over 24 h; every other page keeps
            // the per-batch 20 % rule (D-I8b); $feedPage is set before the transaction (round 4: feed rows requeue the job's items)
            $b=self::boundRoom($o,$count-$skipped,count($kept),$feedPage); $boundHit=$b['hit'];
            if ($boundHit) { $kept=array_slice($kept,0,(int)$b['room'],true); }
            $insert=[]; $requeued=0;
            foreach ($kept as $remote=>$payload) {
                if (isset($requeue[$remote])) { $this->requeueItem($j['job_id'],$requeue[$remote],$payload); $requeued++; continue; }
                $insert[]=['job_id'=>$j['job_id'],'remote_id'=>(int)$remote,'vod_id'=>0,'state'=>'pending','payload'=>$this->json($payload),'translations'=>'{}','locale_cursor'=>1,'before_image'=>'','after_image'=>'','message'=>''];
            }
            if ($requeued) { $o['_feed_requeued']=(int)($o['_feed_requeued'] ?? 0)+$requeued; self::trace('feed_requeue:'.$requeued); }
            foreach ((array)($r['rejected'] ?? []) as $rid=>$reason) { if (preg_match('/^[1-9][0-9]{0,9}$/D',(string)$rid) && (float)$rid<=2147483647 && !isset($seen[(int)$rid])) { $insert[]=['job_id'=>$j['job_id'],'remote_id'=>(int)$rid,'vod_id'=>0,'state'=>'failed','payload'=>'{}','translations'=>'{}','locale_cursor'=>1,'before_image'=>'','after_image'=>'','message'=>mb_substr('Refused provider row: '.(string)$reason.'.',0,255,'UTF-8')]; $seen[(int)$rid]=true; } }
            if ($mode==='selected') { $got=array_fill_keys($ids,true); foreach ($selectedIds as $sid) { if (!isset($got[$sid]) && !isset($seen[$sid])) { $insert[]=['job_id'=>$j['job_id'],'remote_id'=>$sid,'vod_id'=>0,'state'=>'failed','payload'=>'{}','translations'=>'{}','locale_cursor'=>1,'before_image'=>'','after_image'=>'','message'=>ImportProviders::text('selected_missing','Selected video was not returned by the provider.')]; } } }
            if ($insert) { Db::name('avdb_import_item')->insertAll($insert); }
            if (!$feedPage) { $o['_bound_n']=(int)$b['queued']+count($insert); }
            $set+=$feedPage ? $this->feedAnomalyHold($o,$provider,$j['job_id']) : $this->anomalyHold($o,$provider,$j['job_id']);
            $o=$this->screenCounters($o);
            $total=(int)$b['queued']+count($insert);
            if ($boundHit) {
                $finished=true; $o['_size_hold']=1;
                $set['state']=self::stateFor('paused',$provider);
                $set['error']=ImportProviders::text('size_bound','The provider returned more videos than estimated ({n} of about {est}). The import stopped at 1.2 x the estimate.',['n'=>$total,'est'=>(int)ceil($o['size_bound']/self::SIZE_BOUND)]);
                self::trace('size_bound');
            }
            // round 4 (flood-r4 N17): the page's time (half weight per page) for the step admission; progress clears the cut counter
            $el=microtime(true)-$t0; $this->lastDiscSec=$el; $o['_disc_s']=round(isset($o['_disc_s']) ? 0.5*(float)$o['_disc_s']+0.5*$el : $el,3); unset($o['_db_cuts']);
            $set['discovery_done']=$finished?1:0; $set['options']=$this->json($o);
            Db::name('avdb_import_job')->where(['job_id'=>$j['job_id'],'lock_token'=>$token])->update($set);
            Db::commit();
        } catch (\Throwable $e) { Db::rollback(); throw $e; }
        // D-I1 prefetch: CLI id-window jobs start the next window now; the apply batches pump it.
        if ($mode==='windows' && $rung==='' && !$finished && PHP_SAPI==='cli' && !$yielded && method_exists($p,'prefetchWindow')) { try { $p->prefetchWindow((int)$o['win_cursor'],(int)$o['win_upper']); } catch (\Throwable $e) { /* the next discover asks normally */ } }
    }

    // ------------------------------------------------------------------ xvidexport snapshot (contract section 4)

    /** A full (windows) job reads the snapshot when the provider offers one and the job has not started on id windows. */
    private function useSnapshot(array $o, $p)
    {
        if (!empty($o['snap'])) { return ($o['snap']['phase'] ?? '') !== 'off'; }
        if (!empty($o['win_upper']) || ($o['via'] ?? '')==='windows' || !method_exists($p,'manifest')) { return false; }
        try { return $p->manifest()!==null; } catch (ProviderWaitException $e) { throw $e; } catch (\Throwable $e) { return false; }
    }
    private static function snapPath(array $s, array $sh)
    {
        $dir=XvidClient::exportDir(); if ($dir==='') { throw new \RuntimeException('The runtime folder is not writable (runtime/import/xvidexport).'); }
        $dir.=(int)$s['snap_gen'].DIRECTORY_SEPARATOR; if (!is_dir($dir) && !@mkdir($dir,0755,true) && !is_dir($dir)) { throw new \RuntimeException('The runtime folder is not writable (runtime/import/xvidexport).'); }
        return $dir.$sh['file'].'.part';
    }
    /** Remove downloaded snapshot files of generations other than $keepGen (a finished or replaced snapshot). */
    private static function snapCleanup($keepGen)
    {
        $dir=XvidClient::exportDir(); if ($dir==='') { return; }
        foreach (glob($dir.'*',GLOB_ONLYDIR) ?: [] as $g) { if (basename($g)!==(string)(int)$keepGen && preg_match('/^[0-9]{1,12}$/D',basename($g))) { foreach (glob($g.DIRECTORY_SEPARATOR.'*.part') ?: [] as $f) { @unlink($f); } @rmdir($g); } }
    }
    /** Checkpoint keys of builds before the no-limit build (gen, i, dl, last, fallback) -> the A4 names. */
    private static function snapMigrate(array &$s)
    {
        foreach (['gen'=>'snap_gen','i'=>'shard_i','dl'=>'dl_bytes','last'=>'last_id'] as $old=>$new) { if (array_key_exists($old,$s) && !array_key_exists($new,$s)) { $s[$new]=$s[$old]; unset($s[$old]); } }
        if (($s['phase'] ?? '')==='fallback') { $s['phase']='range'; $s['k_off']=1; }
    }
    /** Shard $i of the checkpoint as an array; lo / hi = the id window still to do (whole shard unless a manifest switched). */
    private static function snapShard(array $s, $i)
    {
        $row=$s['shards'][(int)$i] ?? null; if (!$row) { return null; }
        return ['file'=>$row[0],'min_id'=>(int)$row[1],'max_id'=>(int)$row[2],'rows'=>(int)$row[3],'bytes'=>(int)$row[4],'sha256'=>$row[5],'lo'=>(int)($row[6] ?? $row[1]),'hi'=>(int)($row[7] ?? $row[2])];
    }
    /** Phase after the last shard: the changes feed, then the removed feed (when the manifest lists them), then done. */
    private static function snapAfterShards(array $s)
    {
        if (!empty($s['changes'])) { return 'changes'; }
        if (!empty($s['removed'])) { return 'removed'; }
        return 'done';
    }
    /**
     * Manifest switch (contract section 2: a new snapshot replaces the manifest; old shards stay readable >= 24 h). A 404
     * on the shard being downloaded: refresh the manifest; when it is a new generation, everything at or below the top of
     * the current shard's window is still to do (shards go newest id range first and nothing of the current shard was
     * applied yet), so the remaining list becomes the new shards that overlap 1..hi, each with that window. Rows changed
     * between the two generations come from changes?since=<old generated_at>. Returns true when the list was replaced.
     */
    private function snapSwitch(array &$s, $p, array $sh)
    {
        if ((int)($s['switched'] ?? 0)>=5 || !method_exists($p,'manifest')) { return false; }
        $m=$p->manifest(true);
        if (!$m || (int)$m['generated_at']===(int)($s['gen_now'] ?? $s['snap_gen'])) { return false; }
        $hi=(int)$sh['hi']; $rest=[];
        $shards=$m['shards']; usort($shards,function ($a,$b) { return $b['max_id'] <=> $a['max_id']; });
        foreach ($shards as $x) { if ((int)$x['min_id']>$hi) { continue; } $rest[]=[$x['file'],(int)$x['min_id'],(int)$x['max_id'],(int)$x['rows'],(int)$x['bytes'],$x['sha256'],(int)$x['min_id'],min((int)$x['max_id'],$hi)]; }
        $s['shards']=array_merge(array_slice($s['shards'],0,(int)$s['shard_i']),$rest);
        $s['switched']=(int)($s['switched'] ?? 0)+1; $s['gen_now']=(int)$m['generated_at'];
        $s['phase']=isset($s['shards'][(int)$s['shard_i']]) ? 'download' : self::snapAfterShards($s);
        $s['dl_bytes']=0; $s['tries']=0;
        self::trace('snapshot:switch');
        return true;
    }
    /**
     * One snapshot discovery step (also the changes / removed feed of a feed job). Checkpoint (options.snap, A4 names):
     * snap_gen (generated_at of the snapshot the job started from; changes / removed since it), shards (newest id range
     * first: [file, min, max, rows, bytes, sha256, lo, hi]), shard_i, phase download | apply | range | changes | removed |
     * hide | done | off, dl_bytes (bytes on disk), tries, uoff (uncompressed offset), line, last_id (last applied id of
     * the shard), refused, next_at, feed (changes cursor), feed_last, rm (removed cursor), rm_ids, rm_max, k_* (keyset
     * fallback of a range), fb (id-window fallback of a range). Download and verification steps return null (checkpoint
     * written here); the other steps return ['list','rejected','finished','hold'] and the caller writes the checkpoint
     * together with the queued items (one transaction).
     */
    private function snapshotStep($j,array &$o,$token,$p,$provider,$limit)
    {
        if (empty($o['snap'])) {
            $m=method_exists($p,'manifest') ? $p->manifest() : null;
            if ($m===null) { $o['snap']=['phase'=>'off']; $this->checkpoint($j['job_id'],$token,['options'=>$this->json($o)]); self::trace('snapshot:none'); return null; }
            $shards=$m['shards']; usort($shards,function ($a,$b) { return $b['max_id'] <=> $a['max_id']; });
            $o['snap']=['snap_gen'=>(int)$m['generated_at'],'max_id'=>(int)$m['max_id'],'total'=>(int)$m['catalog_total'],'changes'=>!empty($m['changes']) ? 1 : 0,'removed'=>!empty($m['removed']) ? 1 : 0,
                'shards'=>array_map(function ($sh) { return [$sh['file'],$sh['min_id'],$sh['max_id'],$sh['rows'],$sh['bytes'],$sh['sha256']]; },$shards),
                'shard_i'=>0,'phase'=>'download','dl_bytes'=>0,'tries'=>0,'uoff'=>0,'line'=>0,'last_id'=>0,'refused'=>0,'next_at'=>0,'feed'=>'','feed_last'=>'','rm'=>'','rm_ids'=>[],'rm_max'=>0,'fb'=>0,'started'=>time()];
            $o['_source']='snapshot';
            $o['remote_total']=(int)$m['catalog_total']; if ($o['remote_total']>0) { self::storeTotal($provider,0,$o['remote_total']); }
            if (empty($o['size_bound']) && $o['remote_total']>0) { $o['size_bound']=(int)ceil(self::SIZE_BOUND*($limit>0 ? min($limit,$o['remote_total']) : $o['remote_total'])); }
            self::snapCleanup($o['snap']['snap_gen']);
            $this->checkpoint($j['job_id'],$token,['options'=>$this->json($o)]);
            self::trace('snapshot:start');
            return null;
        }
        self::snapMigrate($o['snap']);
        $s=&$o['snap'];
        $sh=self::snapShard($s,(int)($s['shard_i'] ?? 0));
        if (!$sh && in_array($s['phase'],['download','apply','range'],true)) { $s['phase']=self::snapAfterShards($s); }
        $next=function () use (&$s) {
            $s['shard_i']=(int)$s['shard_i']+1; $s['phase']='download'; $s['dl_bytes']=0; $s['tries']=0; $s['uoff']=0; $s['line']=0; $s['last_id']=0; $s['refused']=0; $s['fb']=0; $s['next_at']=microtime(true)+1.0;
            unset($s['k_cur'],$s['k_started']);
            if (!isset($s['shards'][$s['shard_i']])) { $s['phase']=self::snapAfterShards($s); }
        };
        $none=['list'=>[],'rejected'=>[],'finished'=>false,'hold'=>''];
        if ($s['phase']==='download') {
            // import-r2 N2: the back-off after a failed try (short file, bad sha256) holds: up to 1 s here, a longer wait ends the step
            // with retry_at (the command-line runner sleeps until then, the admin page comes back then)
            $wait=(float)$s['next_at']-microtime(true);
            if ($wait>1.0) { throw new ProviderWaitException($provider,(int)ceil((float)$s['next_at']),ImportProviders::text('snap_retry','Waiting before the next try of a snapshot file.')); }
            if ($wait>0) { usleep((int)round($wait*1000000)); }
            // a download call needs step time left (ProviderHttp::download returns at once below 1 s): else the next step downloads
            if ($this->stepDeadline>0 && $this->stepDeadline-microtime(true)<self::SNAP_DOWNLOAD_MIN_S) { $this->stepYield=true; return null; }
            $lock=(string)config('database.database').'_xvexport';
            $got=Db::query('SELECT GET_LOCK(?, 0) AS l',[$lock]);
            if ((string)($got[0]['l'] ?? '')!=='1') { throw new ProviderWaitException($provider,time()+10,ImportProviders::text('snap_busy','Another snapshot download of this site is running.')); }
            $gone=false; $meta=null;
            try {
                $path=self::snapPath($s,$sh);
                $deadline=$this->stepDeadline>0 ? $this->stepDeadline : microtime(true)+20;
                // the provider's pacer slot is taken inside downloadShard() before every request (import-r2 N2)
                try { $s['dl_bytes']=(int)$p->downloadShard($sh,$path,(int)$s['dl_bytes'],$deadline,$meta); }
                catch (ProviderHttpException $e) { if ((int)$e->status!==404) { throw $e; } $gone=true; }
            } finally { try { Db::query('SELECT RELEASE_LOCK(?) AS r',[$lock]); } catch (\Throwable $e) { /* connection gone */ } }
            if ($gone) {
                // 404: a new manifest (switch: continue with its shards below the current window top) or the shard is gone
                // (its id range through the keyset export API, else id windows)
                @unlink($path); self::trace('snapshot:404');
                if (!$this->snapSwitch($s,$p,$sh)) { $s['phase']='range'; $s['fb']=0; }
            } elseif (is_array($meta) && !empty($meta['short'])) {
                // import-r2 N2: the transfer ended normally (HTTP 200 / 206, or 416 below the announced size; not the step deadline)
                // with fewer bytes than the manifest announces: an error page a CDN / proxy serves with status 200, a server that
                // ignores Range, or a truncated file. A failed try like a bad sha256: once more after SNAP_RETRY_S, then the shard's
                // id range through the keyset export API (else id windows). Before this fix the job asked again at once, forever
                // (25,416 requests in 59 s, tries 0, no error).
                @unlink($path); $s['dl_bytes']=0; $s['tries']=(int)$s['tries']+1; $o['_snap_short']=(int)($o['_snap_short'] ?? 0)+1;
                $s['next_at']=microtime(true)+min(60.0,self::SNAP_RETRY_S*(2 ** max(0,(int)$s['tries']-1)));
                $o['_snap_short_last']=mb_substr($sh['file'].': HTTP '.(int)($meta['status'] ?? 0).', '.(int)($meta['size'] ?? 0).' of '.(int)$sh['bytes'].' bytes',0,200,'UTF-8');
                self::trace('snapshot:short');
                if ($s['tries']>=2) { $s['phase']='range'; $s['fb']=0; unset($s['k_cur'],$s['k_started']); }
            } elseif ($s['dl_bytes']>=$sh['bytes']) {
                if (!hash_equals((string)$sh['sha256'],(string)hash_file('sha256',$path))) {
                    @unlink($path); $s['dl_bytes']=0; $s['tries']=(int)$s['tries']+1; self::trace('snapshot:bad_sha');
                    $s['next_at']=microtime(true)+min(60.0,self::SNAP_RETRY_S*(2 ** max(0,(int)$s['tries']-1)));
                    if ($s['tries']>=2) { $s['phase']='range'; $s['fb']=0; $o['_snap_bad_sha']=(int)($o['_snap_bad_sha'] ?? 0)+1; }
                } else {
                    $s['phase']='apply'; $s['uoff']=0; $s['line']=0; $s['last_id']=$sh['min_id']-1; $s['refused']=0;
                    $o['_snap_dl_mb']=round((float)($o['_snap_dl_mb'] ?? 0)+$sh['bytes']/1048576,1); $o['_snap_shards']=(int)($o['_snap_shards'] ?? 0)+1;
                }
            }
            $this->checkpoint($j['job_id'],$token,['options'=>$this->json($o)]);
            return null;
        }
        if ($s['phase']==='apply') {
            $path=self::snapPath($s,$sh);
            if (!is_file($path)) { $s['phase']='download'; $s['dl_bytes']=0; $this->checkpoint($j['job_id'],$token,['options'=>$this->json($o)]); return null; }
            try { $r=$p->readShard($path,(int)$s['uoff'],$this->smallBatches ? self::STEP_SMALL_BATCH : ($this->bulk ? 500 : 100),$sh,(int)$s['last_id'],(int)$s['line']); }
            catch (\RuntimeException $e) {
                if ($e->getCode()!==XvidClient::SHARD_BOMB) { throw $e; }
                // gzip bomb: the file is dropped and the rest of its window comes through the keyset API (rows applied so
                // far stay; the range restarts above the last applied id)
                if (method_exists($p,'closeShard')) { $p->closeShard(); } @unlink($path);
                $o['_snap_bomb']=(int)($o['_snap_bomb'] ?? 0)+1; $o['_snap_refused_samples'][]=mb_substr($sh['file'].': '.$e->getMessage(),0,200,'UTF-8');
                if ((int)$s['last_id']>=(int)$sh['lo']) { $s['shards'][(int)$s['shard_i']][6]=(int)$s['last_id']+1; $s['shards'][(int)$s['shard_i']][7]=$sh['hi']; }
                $s['phase']='range'; $s['fb']=0; unset($s['k_cur'],$s['k_started']); self::trace('snapshot:bomb');
                $this->checkpoint($j['job_id'],$token,['options'=>$this->json($o)]);
                return null;
            }
            $s['uoff']=(int)$r['uoff']; $s['line']=(int)$s['line']+(int)$r['lines']; $s['last_id']=(int)$r['last_id']; $s['refused']=(int)$s['refused']+count($r['rejected']);
            $o['_snap_lines']=(int)($o['_snap_lines'] ?? 0)+(int)$r['lines'];
            if (!empty($r['skipped'])) { $o['_snap_window_skipped']=(int)($o['_snap_window_skipped'] ?? 0)+(int)$r['skipped']; }
            // refused lines: counted, and the first 20 kept readable in the job (keys are '#line N', never remote ids)
            if ($r['rejected']) { $o['_snap_refused']=(int)($o['_snap_refused'] ?? 0)+count($r['rejected']); foreach ($r['rejected'] as $where=>$why) { if (count((array)($o['_snap_refused_samples'] ?? []))>=20) { break; } $o['_snap_refused_samples'][]=mb_substr($sh['file'].' '.$where.': '.$why,0,200,'UTF-8'); } }
            $hold='';
            if ($s['refused']>max(20,0.05*$sh['rows'])) { $hold=ImportProviders::text('snap_refused','The snapshot file {file} has {n} refused rows (more than 5 %). The import is paused; resume to continue with the next rows.',['file'=>$sh['file'],'n'=>$s['refused']]); $s['refused']=0; }
            if ($r['eof']) { if (method_exists($p,'closeShard')) { $p->closeShard(); } @unlink($path); $next(); }
            return ['list'=>$r['rows'],'rejected'=>$r['rejected'],'finished'=>false,'hold'=>$hold];
        }
        if ($s['phase']==='range') {
            // the shard could not be used (sha mismatch twice, gone without a new manifest): its id window through the
            // keyset export API (fixed upper id, rows < cursor), else id windows of 500
            $lo=(int)$sh['lo']; $hi=(int)$sh['hi'];
            if (empty($s['k_off']) && method_exists($p,'exportPage')) {
                try {
                    if (empty($s['k_upper'])) { $probe=$p->exportPage(0,0,1); $s['k_upper']=max(1,(int)$probe['upper_id']); $o['_snap_keyset']=(int)($o['_snap_keyset'] ?? 0)+1; return $none; }
                    $cursor=!empty($s['k_started']) ? (int)$s['k_cur'] : ($hi>=(int)$s['k_upper'] ? 0 : $hi+1);
                    $r=$p->exportPage($cursor,(int)$s['k_upper']);
                    $o['_snap_keyset']=(int)($o['_snap_keyset'] ?? 0)+1;
                    $rows=array_values(array_filter($r['list'],function ($row) use ($lo,$hi) { $id=(int)$row['vod_id']; return $id>=$lo && $id<=$hi; }));
                    $nc=(int)$r['next_cursor'];
                    if ($nc===0 || $nc<=$lo) { $next(); } else { $s['k_cur']=$nc; $s['k_started']=1; }
                    return ['list'=>$rows,'rejected'=>(array)($r['rejected'] ?? []),'finished'=>false,'hold'=>''];
                } catch (ProviderHttpException $e) {
                    if (!in_array((int)$e->status,[400,404,410],true) && $e->getCode()!==ProviderHttpException::MALFORMED) { throw $e; }
                    $s['k_off']=1; self::trace('snapshot:no_keyset');
                    return $none;
                }
            }
            $cursor=(int)$s['fb']; if ($cursor<0 || $cursor>$hi) { $cursor=0; }
            $r=$p->cursorPage($cursor,$hi);
            $rows=array_values(array_filter($r['list'],function ($row) use ($lo) { return (int)$row['vod_id']>=$lo; }));
            $o['_snap_windows']=(int)($o['_snap_windows'] ?? 0)+1;
            if (!empty($r['exhausted']) || (int)$r['next_cursor']<$lo) { $next(); } else { $s['fb']=(int)$r['next_cursor']; }
            return ['list'=>$rows,'rejected'=>(array)($r['rejected'] ?? []),'finished'=>false,'hold'=>''];
        }
        if ($s['phase']==='changes') {
            // rows changed or added since the snapshot was written (contract section 3)
            $since=max(1,(int)$s['snap_gen']);
            try { $r=$p->changes($since,(string)$s['feed'],1000); }
            catch (ProviderHttpException $e) { if (!in_array((int)$e->status,[400,404,410],true)) { throw $e; } $r=['list'=>[],'rejected'=>[],'next_cursor'=>'','last_cursor'=>'']; self::trace('snapshot:no_changes_feed'); }
            $o['_snap_feed_pages']=(int)($o['_snap_feed_pages'] ?? 0)+1; $o['_feed_rows']=(int)($o['_feed_rows'] ?? 0)+count($r['list']);
            if ((string)($r['last_cursor'] ?? '')!=='') { $s['feed_last']=(string)$r['last_cursor']; }
            $s['feed']=(string)$r['next_cursor'];
            if ($s['feed']==='') { $s['phase']=!empty($s['removed']) ? 'removed' : 'done'; }
            return ['list'=>$r['list'],'rejected'=>(array)($r['rejected'] ?? []),'finished'=>false,'hold'=>''];
        }
        if ($s['phase']==='removed') {
            // videos withdrawn since the snapshot (or since the stored cursor of a feed job): collected first, hidden only
            // when their number stays inside the breaker (2 % of the provider's videos, at most 5,000; D-I8 / L15)
            $since=(int)($s['rm_since'] ?? $s['snap_gen']);
            if (!isset($s['rm_limit'])) { $total=$this->providerVideos($provider); $s['rm_limit']=min(self::HIDE_MAX,(int)floor(self::HIDE_MAX_RATIO*max(1,$total))); $s['rm_total']=$total; }
            try { $r=$p->removed($since,(string)$s['rm']); }
            catch (ProviderHttpException $e) { if (!in_array((int)$e->status,[400,404,410],true)) { throw $e; } $r=['list'=>[],'next_cursor'=>'','max_at'=>0]; self::trace('snapshot:no_removed_feed'); }
            $o['_removed_pages']=(int)($o['_removed_pages'] ?? 0)+1;
            $ids=array_fill_keys(array_map('intval',(array)$s['rm_ids']),true);
            foreach ($r['list'] as $e) { $ids[(int)$e[0]]=true; }
            $s['rm_max']=max((int)$s['rm_max'],(int)$r['max_at']);
            if (count($ids)>max(1,(int)$s['rm_limit'])) {
                $o['_notice']=self::hideNotice(count($ids),(int)$s['rm_total']); $o['_removed_aborted']=count($ids);
                $s['rm_ids']=[]; $s['phase']='done'; self::trace('removed_aborted');
                return $none;
            }
            $s['rm_ids']=array_keys($ids); $s['rm']=(string)$r['next_cursor'];
            if ($s['rm']==='') { $s['phase']=$s['rm_ids'] ? 'hide' : 'done'; }
            return $none;
        }
        if ($s['phase']==='hide') {
            $batch=array_slice((array)$s['rm_ids'],0,self::PRESENCE_WINDOW);
            $n=$batch ? $this->hideRemote($provider,array_map('intval',$batch)) : 0;
            $o['_hidden']=(int)($o['_hidden'] ?? 0)+$n;
            $s['rm_ids']=array_values(array_slice((array)$s['rm_ids'],self::PRESENCE_WINDOW));
            if (!$s['rm_ids']) { $s['phase']='done'; }
            return $none;
        }
        // done: remember the feed cursors for the hourly plan (changes since the last row read, removed since the newest
        // removal seen), remove downloaded files
        if (empty($s['stored']) && (!empty($s['changes']) || !empty($s['removed']))) {
            $cursor=(string)($s['feed_last'] ?? '') !== '' ? (string)$s['feed_last'] : (int)$s['snap_gen'].':0';
            self::storeFeed($provider,$cursor,max((int)($s['rm_since'] ?? $s['snap_gen']),(int)$s['rm_max']-1));
            $s['stored']=1;
        }
        self::snapCleanup(0);
        return ['list'=>[],'rejected'=>[],'finished'=>true,'hold'=>''];
    }
    /**
     * Keyset export API for a whole-catalog job when the provider has no snapshot (contract section 3, fallback rung of
     * A4): pages of 1,000 newest first under a fixed upper id. The first page fixes upper_id and the total; a provider
     * without the endpoint (400 / 404 / 410, or an answer that breaks the rules on the first page) turns the job to id
     * windows. Returns null when only the checkpoint changed.
     */
    private function keysetStep($j,array &$o,$token,$p,$provider,$limit)
    {
        if (!method_exists($p,'exportPage')) { $o['ks']=['off'=>1]; $this->checkpoint($j['job_id'],$token,['options'=>$this->json($o)]); return null; }
        if (empty($o['ks'])) { $o['ks']=['cursor'=>0,'upper'=>0,'pages'=>0]; }
        $k=&$o['ks'];
        try { $r=$p->exportPage((int)$k['cursor'],(int)$k['upper']); }
        catch (ProviderWaitException $e) { throw $e; }
        catch (\Throwable $e) {
            $kind=ProviderPacer::classify($e)['kind'];
            if ((int)$k['pages']>0 || in_array($kind,['rate_limit','transient','challenge'],true)) { throw $e; }
            $o['ks']=['off'=>1,'why'=>mb_substr($e->getMessage(),0,120,'UTF-8')]; self::trace('keyset:off');
            $this->checkpoint($j['job_id'],$token,['options'=>$this->json($o)]);
            return null;
        }
        if ((int)$k['pages']===0) {
            $k['upper']=(int)$r['upper_id'];
            if ($r['total']!==null) { $o['remote_total']=(int)$r['total']; if ($o['remote_total']>0) { self::storeTotal($provider,0,$o['remote_total']); } }
            if (empty($o['size_bound']) && !empty($o['remote_total'])) { $o['size_bound']=(int)ceil(self::SIZE_BOUND*($limit>0 ? min($limit,(int)$o['remote_total']) : (int)$o['remote_total'])); }
            $o['_source']='keyset';
        }
        $k['cursor']=(int)$r['next_cursor']; $k['pages']=(int)$k['pages']+1;
        return ['list'=>$r['list'],'rejected'=>(array)($r['rejected'] ?? []),'finished'=>!empty($r['exhausted'])];
    }
    /**
     * Feed job (mode feed, the hourly plan once a snapshot import stored its cursors): changes since the stored cursor,
     * then the removed feed since the stored time, through the same phases as the end of a snapshot job.
     */
    private function feedStep($j,array &$o,$token,$p,$provider)
    {
        if (empty($o['snap'])) {
            $f=self::feedState($provider);
            if (!$f || !method_exists($p,'changes')) { $o['snap']=['phase'=>'done','stored'=>1,'shards'=>[],'shard_i'=>0]; return ['list'=>[],'rejected'=>[],'finished'=>true,'hold'=>'']; }
            $t=(int)explode(':',$f['cursor'])[0];
            $o['snap']=['phase'=>'changes','snap_gen'=>max(1,$t-1),'feed'=>$f['cursor'],'feed_last'=>$f['cursor'],'rm'=>'','rm_since'=>(int)$f['since'],'rm_ids'=>[],'rm_max'=>0,'shards'=>[],'shard_i'=>0,'changes'=>1,'removed'=>1,'max_id'=>0,'feed_job'=>1,'started'=>time()];
            $o['_source']='feed';
        }
        return $this->snapshotStep($j,$o,$token,$p,$provider,0);
    }
    /** Stored feed cursors of a provider: ['cursor' => "<vod_time>:<vod_id>", 'since' => unix] or null (no snapshot import yet). */
    public static function feedState($provider)
    {
        try { $row=Db::name('avdb_provider_state')->where('provider',(string)$provider)->field('feed_cursor,removed_since')->find(); } catch (\Throwable $e) { return null; }
        if (!$row || !preg_match('/^[0-9]{1,10}:[0-9]{1,10}$/D',(string)($row['feed_cursor'] ?? ''))) { return null; }
        return ['cursor'=>(string)$row['feed_cursor'],'since'=>max(0,(int)($row['removed_since'] ?? 0))];
    }
    private static function storeFeed($provider,$cursor,$since)
    {
        if (!preg_match('/^[0-9]{1,10}:[0-9]{1,10}$/D',(string)$cursor)) { return; }
        try {
            $table=(string)config('database.prefix').'avdb_provider_state';
            if (!preg_match('/^[A-Za-z0-9_]+$/D',$table)) { return; }
            Db::execute('INSERT IGNORE INTO `'.$table.'` (`provider`,`updated_at`) VALUES (?,?)',[(string)$provider,time()]);
            Db::name('avdb_provider_state')->where('provider',(string)$provider)->update(['feed_cursor'=>(string)$cursor,'removed_since'=>max(0,(int)$since)]);
            self::trace('feed_stored');
        } catch (\Throwable $e) { error_log('[AVDB] feed cursor: '.get_class($e)); }
    }
    /**
     * Hide the local videos of provider ids withdrawn at the provider (removed feed): at once and reversibly, like the
     * presence recheck (map.state 1, vod_status 0, missed_at set when empty, edge purge, search touch, counts, page map).
     * A row that comes back in a later answer is visible again (apply restores map.state 1). Returns the videos hidden.
     */
    private function hideRemote($provider,array $remoteIds)
    {
        $vods=[];
        Db::startTrans(); try {
            $rows=Db::name('avdb_import_map')->where('provider',$provider)->where('remote_id','in',$remoteIds)->where('state',0)->where('vod_id','>',0)->field('remote_id,vod_id')->lock(true)->select();
            $vods=array_values(array_unique(array_map('intval',array_column($rows,'vod_id'))));
            if ($rows) {
                $rids=array_map('intval',array_column($rows,'remote_id'));
                Db::name('avdb_import_map')->where('provider',$provider)->where('remote_id','in',$rids)->update(['state'=>1]);
                Db::name('avdb_import_map')->where('provider',$provider)->where('remote_id','in',$rids)->where('missed_at',0)->update(['missed_at'=>$this->now()]);
            }
            if ($vods) { Db::name('vod')->where('vod_id','in',$vods)->update(['vod_status'=>0]); $this->ftQueueTx($vods); } // fp-port-c: queued with the hide
            Db::commit();
        } catch (\Throwable $e) { Db::rollback(); throw $e; }
        if ($vods) { $this->afterHide($vods); }
        return count($vods);
    }
    /** Caches, search, counts and the page map after videos were hidden (presence recheck, removed feed). */
    private function afterHide(array $vods)
    {
        // fp-port-c (FINISH-PORT-REVIEW-b P2-1): the filter vocabulary re-reads the hidden videos (their item-path hashes go: the
        // drain deletes their search documents, so a later restore must be checked again); first, it never throws
        self::contract('FilterVocab','touched',[$vods]);
        self::contract('EdgeCache','purge',[$vods]); self::contract('FtIndex','touch',[$vods]); self::contract('ScaleCounts','markDirty',['presence']);
        try {
            $m=new Vod(); $min=null; $types=[];
            foreach (Db::name('vod')->where('vod_id','in',$vods)->select() as $v) { $m->invalidateDetailCache($v); $t=(int)($v['vod_time'] ?? 0); $min=$min===null ? $t : min($min,$t); foreach (['type_id','type_id_1'] as $k) { if ((int)($v[$k] ?? 0)>0) { $types[(int)$v[$k]]=(int)$v[$k]; } } }
            if ($min!==null) { self::contract('ListPageMap','dirty',[(int)$min,array_values($types)]); }
        } catch (\Throwable $e) { error_log('[AVDB] Cache maintenance: '.$e->getMessage()); }
        self::contract('Vod','bumpListRevision',[true]);
    }

    // ------------------------------------------------------------------ storage target (XVID-NOLIMIT-BRIEF A2)

    /**
     * A2: at the storage target (StorageGuard::importTarget: 0.8 x the database limit, or the free disk minus the
     * reserve of the closing search step) the job COMPLETES with stop_reason 'storage_target': pending items are dropped
     * (nothing is written after the target), the search / counts / caches close as at a normal end, nothing pauses
     * site-wide. The 0.9 guard (isFull) and the storage-error trip stay as they were (G24). Returns true when stopped.
     */
    private function targetReached($j,array $o,$token)
    {
        if (($o['type'] ?? 'import')!=='import' || ($o['mode'] ?? '')==='presence') { return false; }
        $t=$this->targetCheck($j['job_id'],$o,self::jobProvider($j));
        if (empty($t['reached'])) { return false; }
        $this->stopAtTarget($j,$o,$token,$t);
        return true;
    }
    /**
     * The decision of targetReached() without any write (tests drive it with StorageGuard::setProbe() and $status = the shared
     * measurement to decide on; default StorageGuard::status()). Sets nearTarget (check every batch). Returns importTarget()'s
     * array, from targetDb() when the exact path ran (db_now_mb, db_exact_mb, db_mb_per_video_kb, exact_measurements), with
     * db_pred_mb (the predicted size before the exact path).
     */
    private function targetCheck($jobId, array $o, $provider, ?array $status = null)
    {
        $s=$status ?? StorageGuard::status(); $t=StorageGuard::importTarget($s);
        // close to the free-disk target (less than 2 GB left): a measurement at most 5 s old and a check every batch (free disk
        // space is measured directly, it does not lag)
        $diskNear=empty($t['reached']) && $t['disk_mb']!==null && $t['disk_mb']<StorageGuard::FREE_MIN_MB;
        if ($diskNear && $status===null && time()-(int)($s['checked_at'] ?? 0)>=self::TARGET_NEAR_S) { $s=StorageGuard::refresh(true); $t=StorageGuard::importTarget($s); }
        // import-r3 N7: the database target is decided on the predicted size (the shared, lagging measurement + the videos this
        // process inserted since it was taken); the exact path (targetDb: ANALYZE + measurement, prediction from the measured size
        // per video in between) runs from the first batch whenever the predicted gap is below max(256 MB, 30 % of the target), and
        // a 'reached' read from the lagging measurement is confirmed there by an exact measurement before the job stops
        $dbNear=false;
        if ($t['db_mb']!==null && $t['db_now_mb']!==null && (empty($t['reached']) || ($t['reason'] ?? '')==='db_limit')) {
            $kb=self::targetKbPerVideo($o,(string)$provider);
            $pred=$this->predictedDb($jobId,(float)$t['db_now_mb'],(int)($s['checked_at'] ?? 0),$kb);
            $t['db_pred_mb']=(int)round($pred);
            if (!empty($t['reached']) || self::targetExactDue((float)$t['db_mb'],$pred)) { $t=$this->targetDb($jobId,$t,$kb); $dbNear=empty($t['reached']); }
            // within twice that gap every batch is checked (a prediction from the stored measurement: no query)
            else { $dbNear=self::targetExactDue((float)$t['db_mb'],$pred,2.0); }
        }
        $this->nearTarget=($diskNear || $dbNear) && empty($t['reached']);
        return $t;
    }
    /**
     * import-r3 N7 (pure): exact measurements of the database target are due when the predicted gap to it is below $factor x
     * max(TARGET_EXACT_GAP_MB, TARGET_EXACT_GAP_RATIO x target): InnoDB statistics (information_schema sizes) lag behind a bulk
     * import by up to ~10-15 % of a table, and the shared measurement is up to CACHE_SECONDS old.
     */
    public static function targetExactDue($targetMb, $predictedMb, $factor = 1.0)
    {
        $target = (float)$targetMb;
        if ($target <= 0) { return false; }
        return $target - (float)$predictedMb < max(0.0, (float)$factor) * max((float)self::TARGET_EXACT_GAP_MB, self::TARGET_EXACT_GAP_RATIO * $target);
    }
    /**
     * import-r3 N7 (pure): videos inserted by this process since a measurement taken at unix time $checkedAt, from the samples
     * [[unix time, inserted videos], ...] (oldest first) of its storage checks: inserted now minus the count of the newest sample
     * taken before that second; no such sample = everything this process inserted (the measurement is older than its samples).
     */
    public static function rowsSince(array $samples, $checkedAt, $insertedNow)
    {
        $base = 0;
        foreach ($samples as $x) {
            if (!is_array($x) || count($x) < 2) { continue; }
            if ((float)$x[0] < (float)$checkedAt) { $base = (int)$x[1]; } else { break; }
        }
        return max(0, (int)$insertedNow - $base);
    }
    /**
     * import-r3 N7: KB per imported video for the storage-target prediction before this job measured its own: TARGET_DEFAULT_KB,
     * and for AVDB jobs that keep translations the row + its translation rows (ImportProviders model: 3.1 KB + 18 KB for all
     * 16 translations, pro rata).
     */
    private static function targetKbPerVideo(array $o, $provider)
    {
        $kb = self::TARGET_DEFAULT_KB;
        $extra = max(0, count((array)($o['locales'] ?? [])) - 1);
        if ($extra > 0 && $provider === self::PROVIDER) { $kb = max($kb, ImportProviders::KB_AVDB + ImportProviders::KB_AVDB_TRANSLATIONS * min(16, $extra) / 16); }
        return $kb;
    }
    /**
     * import-r3 N7: the database size now, predicted from the shared measurement ($dbNowMb, taken at $checkedAt; information_schema
     * sizes) + the videos this process inserted since then x the larger of $kb and the size per video this job measured. Records a
     * sample [time, inserted videos] per call (at most TARGET_SAMPLES).
     */
    private function predictedDb($jobId, $dbNowMb, $checkedAt, $kb)
    {
        $this->tgtSamples[] = [$this->tgtClock ? (float)call_user_func($this->tgtClock) : microtime(true), $this->insertedRows];
        if (count($this->tgtSamples) > self::TARGET_SAMPLES) { array_shift($this->tgtSamples); }
        if ($this->tgt && ($this->tgt['job'] ?? '') === (string)$jobId) { $kb = max((float)$kb, (float)$this->tgt['bpr'] * 1024); }
        return (float)$dbNowMb + self::rowsSince($this->tgtSamples, $checkedAt, $this->insertedRows) * (float)$kb / 1024;
    }
    /**
     * import-r2 N7: the database part of the storage target from exact measurements (StorageGuard::refreshExact: ANALYZE of the
     * growing tables, then the shared measurement). Exact when this process has none for the job, when the last one is
     * TARGET_EXACT_S old, and before a stop (at most TARGET_NEAR_S old); in between the size is predicted from this process's
     * inserted videos x the measured MB per video (the slope between this process's first exact point and the newest one once
     * TARGET_BPR_MIN_ROWS videos apart, else $defaultKb: TARGET_DEFAULT_KB, more for AVDB jobs with translations). The job stops
     * before the batch that would pass the target. import-r3 N7: a 'reached' that $t carries from the lagging measurement is
     * decided again from exact data (a measurement at most TARGET_NEAR_S old), so the stop is always confirmed; the free-disk part
     * of the target still stops the job when it is reached. Returns $t with db_now_mb (exact or predicted), reached and reason.
     */
    private function targetDb($jobId, array $t, $defaultKb = self::TARGET_DEFAULT_KB)
    {
        $target=(float)$t['db_mb'];
        $clock=$this->tgtClock ?: function () { return microtime(true); };
        $measure=function () use ($clock) { $s=StorageGuard::refreshExact(); return ['mb'=>(float)($s['db_mb'] ?? 0),'rows'=>$this->insertedRows,'at'=>(float)$clock()]; };
        $kb0=max(0.1,min(64.0,(float)$defaultKb));
        $fold=function (array $m) use ($jobId,$kb0) {
            $g=$this->tgt;
            if (!$g || ($g['job'] ?? '')!==(string)$jobId) { $this->tgt=$m+['job'=>(string)$jobId,'p0'=>$m,'bpr'=>$kb0/1024,'exact'=>1]; return; }
            $p0=$g['p0']; $bpr=$g['bpr'];
            if ($m['rows']-$p0['rows']>=self::TARGET_BPR_MIN_ROWS && $m['mb']>$p0['mb']) {
                $slope=max(0.1/1024,min(64.0/1024,($m['mb']-$p0['mb'])/($m['rows']-$p0['rows'])));
                // import-r3 N7: a baseline with less than TARGET_BPR_MIN_MB of growth holds only a few extent steps (the reported size
                // moves in whole extents per index segment), so the default size per video stays the floor until then
                $bpr=$m['mb']-$p0['mb']>=self::TARGET_BPR_MIN_MB ? $slope : max($kb0/1024,$slope);
            }
            $this->tgt=$m+['job'=>(string)$jobId,'p0'=>$p0,'bpr'=>$bpr,'exact'=>(int)$g['exact']+1];
        };
        $lagReached=!empty($t['reached']) && ($t['reason'] ?? '')==='db_limit';
        if ($lagReached) { $t['db_lagging_mb']=$t['db_now_mb']; $t['reached']=false; $t['reason']=''; }
        $mine=$this->tgt && ($this->tgt['job'] ?? '')===(string)$jobId;
        if (!$mine || (float)$clock()-$this->tgt['at']>=self::TARGET_EXACT_S || ($lagReached && (float)$clock()-$this->tgt['at']>=self::TARGET_NEAR_S)) { $fold($measure()); }
        $g=$this->tgt; $batch=$this->bulk ? 500 : 100; $margin=max(self::TARGET_MARGIN_MB,self::TARGET_MARGIN_RATIO*$target);
        $predicted=$g['mb']+max(0,$this->insertedRows-$g['rows'])*$g['bpr'];
        // the prediction says stop: confirm with an exact measurement (unless the newest one is younger than TARGET_NEAR_S)
        if ($predicted+$batch*$g['bpr']+$margin>=$target && (float)$clock()-$g['at']>=self::TARGET_NEAR_S) {
            $fold($measure()); $g=$this->tgt;
            $predicted=$g['mb']+max(0,$this->insertedRows-$g['rows'])*$g['bpr'];
        }
        $t['db_now_mb']=(int)round($predicted); $t['db_exact_mb']=(int)round($g['mb']); $t['db_mb_per_video_kb']=round($g['bpr']*1024,3); $t['exact_measurements']=(int)$g['exact'];
        if ($predicted+$batch*$g['bpr']+$margin>=$target) { $t['reached']=true; $t['reason']='db_limit'; }
        // the lagging 'reached' was not confirmed: the free-disk part of the target (measured directly) still counts
        elseif ($lagReached && $t['disk_mb']!==null && (int)$t['disk_mb']<=0) { $t['reached']=true; $t['reason']='disk'; }
        return $t;
    }
    private function stopAtTarget($j,array $o,$token,array $t)
    {
        $dropped=0;
        Db::startTrans(); try {
            $this->fence($j['job_id'],$token);
            $dropped=(int)Db::name('avdb_import_item')->where(['job_id'=>$j['job_id'],'state'=>'pending'])->delete();
            Db::commit();
        } catch (\Throwable $e) { Db::rollback(); throw $e; }
        $o=$this->decode(Db::name('avdb_import_job')->where('job_id',$j['job_id'])->value('options')) ?: $o;
        $created=(int)Db::name('avdb_import_item')->where(['job_id'=>$j['job_id'],'state'=>'created'])->count()+(int)($o['_bulk_counts']['created'] ?? 0);
        $o['stop_reason']='storage_target'; $o['_stop_target']=$t; $o['_stop_dropped']=$dropped; $o['_stop_at']=time();
        $limitGb=$t['db_mb']!==null ? $t['db_mb'] : (($t['db_now_mb'] ?? 0)+max(0,(int)($t['disk_mb'] ?? 0)));
        $message=ImportProviders::text('storage_target','Stopped at the storage target: {n} videos imported, {used} of {limit} GB used.',['n'=>number_format($created),'used'=>self::gbText($t['db_now_mb']),'limit'=>self::gbText($limitGb)]);
        $this->checkpoint($j['job_id'],$token,['discovery_done'=>1,'error'=>mb_substr($message,0,1000,'UTF-8'),'options'=>$this->json($o)]);
        self::trace('storage_target:'.$t['reason']);
        $this->finishJob($j,$o,$token);
    }
    private static function gbText($mb) { return $mb===null ? '?' : rtrim(rtrim(number_format(max(0,(float)$mb)/1024,2,'.',''),'0'),'.'); }

    // ------------------------------------------------------------------ background steps of web jobs (XVID-NOLIMIT-BRIEF A3, C17)

    /** Tests: folder of import.due (default runtime/scale/). */
    private static $dueDir = null;
    public static function setDueDir($dir) { self::$dueDir = $dir === null ? null : rtrim((string)$dir, '/\\') . DIRECTORY_SEPARATOR; }
    private static function dueFile()
    {
        $dir = self::$dueDir !== null ? self::$dueDir : (defined('RUNTIME_PATH') ? rtrim(RUNTIME_PATH, '/\\') . DIRECTORY_SEPARATOR . 'scale' . DIRECTORY_SEPARATOR : '');
        if ($dir === '' || (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir))) { return ''; }
        return $dir . 'import.due';
    }
    /**
     * Pure rule of tickWeb() (tests): '' = step the job now, else why not. $s: now (+ now_f), due_at (start of the last
     * check), last_end (end of the last background step), job (null | [updated_at, retry_at, lock_until]), cli_seen_at, full
     * (StorageGuard::isFull()), busy (gate slots / load), cli (called from the command line: no 30 s spacing and no
     * cron rule, the 60 s rule stays). Web callers accept a claim TICK_SLACK before TICK_EVERY (lane H's claim moves by whole
     * seconds). A busy server defers the step at most TICK_BUSY_MAX seconds after the job last moved (the end of the last
     * background step, or the job's own updated_at); then '' is returned and tickWeb() steps it with TICK_BUSY_BUDGET.
     * db_left (web callers): seconds of database time the request has left under lane H's per-request deadline (null = none);
     * below TICK_DB_MIN the answer is 'db_time' (the step would be cut mid-batch with its lease held).
     */
    public static function tickDecision(array $s)
    {
        $now = (int)($s['now'] ?? time()); $cli = !empty($s['cli']);
        // flood-r3 N17 / N18: Housekeeping::importTrigger() claims by whole seconds (time() - filemtime >= 30): a claim 29.x s
        // after the last decision is due, not wasted (the claim keeps the 30 s spacing)
        if (!$cli && (float)($s['now_f'] ?? $now) - (float)($s['due_at'] ?? 0) < self::TICK_EVERY - self::TICK_SLACK) { return 'not_due'; }
        $job = $s['job'] ?? null;
        if (!is_array($job)) { return 'no_job'; }
        if ((int)($job['retry_at'] ?? 0) > $now || (int)($job['lock_until'] ?? 0) > $now) { return 'job_waiting'; }
        // an admin page (or another runner) stepped the job in the last 60 s: that page keeps it moving
        $u = (int)($job['updated_at'] ?? 0);
        if ($u > $now - self::TICK_IDLE && $u > (int)($s['last_end'] ?? 0) + 1) { return 'polled'; }
        if (!$cli && (int)($s['cli_seen_at'] ?? 0) > 0 && $now - (int)$s['cli_seen_at'] <= 86400) { return 'cron'; }
        if (!empty($s['full'])) { return 'storage'; }
        // round 3 (N18 re-measure): a web caller's request has too little database time left under lane H's per-request deadline
        // (db_left = DbTimeout::remaining(), null = none): no step that would be cut mid-batch with its lease still held
        if (!$cli && isset($s['db_left']) && $s['db_left'] !== null && (float)$s['db_left'] < self::TICK_DB_MIN) { return 'db_time'; }
        // flood-r3 N17: busy defers the step, at most TICK_BUSY_MAX after the job last moved (a sustained deep-page flood holds the
        // single deep slot of a 2 vCPU host at every tick); then the step runs with TICK_BUSY_BUDGET (tickWeb)
        if (!empty($s['busy']) && $now - max((int)($s['last_end'] ?? 0), $u) < self::TICK_BUSY_MAX) { return 'busy'; }
        return '';
    }
    /** Oldest running web job that no schedule owns (undo jobs too): the one a background step continues. */
    private static function tickJob()
    {
        try { $rows = Db::name('avdb_import_job')->where('state', 'in', self::RUN_STATES)->where('options', 'like', '%"runner":"web"%')->field('job_id,admin_id,options,retry_at,lock_until,updated_at')->order('created_at asc')->limit(20)->select(); }
        catch (\Throwable $e) { return null; }
        foreach ($rows as $r) {
            $o = json_decode((string)$r['options'], true);
            if (!is_array($o) || !empty($o['_schedule_client_id']) || ($o['runner'] ?? '') !== 'web') { continue; }
            return ['job_id' => (string)$r['job_id'], 'admin_id' => (int)$r['admin_id'], 'retry_at' => (int)$r['retry_at'], 'lock_until' => (int)$r['lock_until'], 'updated_at' => (int)$r['updated_at']];
        }
        return null;
    }
    /** D-I11 load check: both deep request slots in use, or the load average above 1.5 x the CPUs (where readable). */
    private static function serverBusy()
    {
        $gate = '\\app\\common\\util\\RequestGate';
        if (class_exists($gate) && method_exists($gate, 'deepBusy')) { try { if (call_user_func([$gate, 'deepBusy'])) { return true; } } catch (\Throwable $e) { /* gate not ready */ } }
        if (function_exists('sys_getloadavg')) {
            $load = @sys_getloadavg(); $cpus = 0;
            if (is_readable('/proc/cpuinfo')) { $cpus = preg_match_all('/^processor\s*:/m', (string)@file_get_contents('/proc/cpuinfo')); }
            if ($cpus < 1 && getenv('NUMBER_OF_PROCESSORS')) { $cpus = (int)getenv('NUMBER_OF_PROCESSORS'); }
            if (is_array($load) && isset($load[0]) && $load[0] > 1.5 * max(1, $cpus)) { return true; }
        }
        return false;
    }
    private static function writeDue($file, array $due)
    {
        $json = json_encode($due);
        if (@file_put_contents($file . '.tmp', $json) === false || !@rename($file . '.tmp', $file)) { @file_put_contents($file, $json, LOCK_EX); @unlink($file . '.tmp'); }
    }
    /**
     * C17: one background step of the oldest running web job, so a whole-catalog import started from the admin page
     * continues with the tab closed. Called by lane H's page-view trigger AFTER fastcgi_finish_request() /
     * litespeed_finish_request() only (never inside a gate slot, never inline in a visitor request), by the schedule
     * tick and by tools/import.php --tick / --run. At most once per 30 s per site (runtime/scale/import.due + flock,
     * non-blocking: a concurrent request returns null at once), only when no admin page or runner stepped the job in
     * the last 60 s, no CLI heartbeat in 24 h (web callers; cron then runs the site), the storage guard is not full and
     * the server is not busy (a busy server defers the step at most TICK_BUSY_MAX, then it runs with TICK_BUSY_BUDGET).
     * Returns null when nothing was checked (not due / locked), else ['stepped' => bool, 'reason' => '' | tickDecision()
     * reason, 'job', 'state', 'created', 'updated', 'failed', 'busy' (stepped although the server was busy)].
     */
    public function tickWeb($budget = 8)
    {
        $cli = PHP_SAPI === 'cli';
        $file = self::dueFile(); if ($file === '') { return null; }
        $fh = @fopen($file . '.lock', 'c'); if (!$fh) { return null; }
        if (!@flock($fh, LOCK_EX | LOCK_NB)) { @fclose($fh); return null; }
        try {
            $due = is_file($file) ? json_decode((string)@file_get_contents($file), true) : [];
            $due = is_array($due) ? $due : [];
            $now = time(); $nowF = microtime(true);
            // cheap exit before any query: visitor requests reach this at most once per claim (lane H's claim moves by whole
            // seconds, so TICK_SLACK early is due: flood-r3 N17 / N18 wasted 4 of 12 and 3 of 10 claims on a sub-second check)
            if (!$cli && $nowF - (float)($due['at'] ?? 0) < self::TICK_EVERY - self::TICK_SLACK) { return null; }
            $job = self::tickJob();
            $busy = $job ? self::serverBusy() : false;
            $decision = self::tickDecision(['now' => $now, 'now_f' => $nowF, 'due_at' => $cli ? 0 : (float)($due['at'] ?? 0), 'last_end' => (int)($due['last_end'] ?? 0), 'job' => $job,
                'cli_seen_at' => $job ? ProviderPacer::cliSeenAt() : 0, 'full' => $job ? StorageGuard::isFull() : false, 'busy' => $busy, 'cli' => $cli,
                'db_left' => $job && !$cli ? self::dbTimeLeft() : null]);
            $due['at'] = round($nowF, 3); $due['reason'] = $decision; $due['busy'] = $busy ? 1 : 0;
            if ($decision !== '') {
                self::writeDue($file, $due);
                // P-O1: no web job to continue: re-add filter FULLTEXT keys a finished / stopped bulk job left (one cheap SELECT when none)
                if ($decision === 'no_job') { self::ftKeysRestore($cli ? 0 : self::FT_WEB_BUDGET); }
                return ['stepped' => false, 'reason' => $decision, 'job' => $job ? $job['job_id'] : ''];
            }
            // flood-r3 N17: the busy rule deferred this step TICK_BUSY_MAX: it runs now with TICK_BUSY_BUDGET seconds of work (this
            // runs after the response, outside every gate slot)
            $stepBudget = max(1.0, min($cli ? 600.0 : 9.0, (float)$budget));
            if ($busy) { $stepBudget = min($stepBudget, self::TICK_BUSY_BUDGET); $due['busy_steps'] = (int)($due['busy_steps'] ?? 0) + 1; }
            $due['job'] = $job['job_id']; self::writeDue($file, $due);
            StorageGuard::allowMeasure(true);
            try { $st = $this->step($job['admin_id'], $job['job_id'], $stepBudget); }
            catch (\Throwable $e) {
                // the admin page took the lease in between, the job was paused / removed, or cleanup is running: no error
                // reaches the caller (a page view or a cron run); the next due tick looks again
                $due['last_end'] = time(); $due['error'] = mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 160, 'UTF-8'); self::writeDue($file, $due);
                return ['stepped' => false, 'reason' => 'step_failed', 'job' => $job['job_id']];
            }
            finally { StorageGuard::allowMeasure(null); }
            $due['last_end'] = time(); $due['steps'] = (int)($due['steps'] ?? 0) + 1; self::writeDue($file, $due);
            self::trace('tick_web');
            return ['stepped' => true, 'reason' => '', 'job' => $job['job_id'], 'state' => $st['state'], 'created' => (int)$st['created'], 'updated' => (int)$st['updated'], 'failed' => (int)$st['failed'], 'busy' => $busy];
        } finally { @flock($fh, LOCK_UN); @fclose($fh); }
    }

    // ------------------------------------------------------------------ apply

    /** Provider row of an item -> normalized row (2.4 field + host rules). Throws when the provider refuses the row. */
    private function normalizeRow($provider,array $raw,array $o)
    {
        $p=$this->prov($provider);
        $extra=array_merge(ImportProviders::extraImageHosts($provider),(array)($o['image_hosts_extra'] ?? []));
        if ($extra && method_exists($p,'allowImageHosts')) { $p->allowImageHosts($extra); }
        $meta=array_intersect_key($raw,['_row_hash'=>1,'_rank'=>1,'_avdb_images'=>1,'_avdb_quality'=>1,'_requeue'=>1]);
        $row=$p->normalize(array_diff_key($raw,$meta));
        return $row+$meta;
    }
    private function processBatch($j,$o,$items,$token)
    {
        $provider=self::jobProvider($j);
        $batch=array_values(array_filter($items,function($i) use($o){return count($o['locales'])>1 && ((int)$i['locale_cursor']<count($o['locales']) || !array_key_exists('_avdb_quality',$this->decode($i['payload'])));}));
        if ($batch) {
            $locales=array_slice($o['locales'],1);
            $ids=array_map('intval',array_column($batch,'remote_id'));
            $p=$this->prov($provider);
            try { $bundle=$p->paced(function () use ($o,$ids,$locales) { return array_key_exists('export_cursor',$o)?$this->client->fetchExportTranslations($ids,$locales):$this->client->fetchTranslations($ids,$locales); }); }
            catch (\Throwable $error) {
                if (!array_key_exists('export_cursor',$o) || $error->getCode()!==413 || count($batch)<=1) { throw $error; }
                $o['_export_batch_limit']=max(1,intdiv(count($batch),2));
                $this->checkpoint($j['job_id'],$token,['options'=>$this->json($o)]);
                return;
            }
            Db::startTrans(); try {
                $this->fence($j['job_id'],$token);
                foreach ($batch as $item) {
                    $current=Db::name('avdb_import_item')->where('item_id',$item['item_id'])->lock(true)->find(); if (!$current || $current['state']!=='pending') { continue; }
                    $row=$this->decode($current['payload']); $entry=$bundle[(int)$current['remote_id']] ?? []; $t=[];
                    foreach (array_slice($o['locales'],1) as $locale) {
                        $payload=self::genuineTranslation((array)($entry['translations'][$locale] ?? []),$locale,$row);
                        if ($payload) { $t[$locale]=$payload; } else { unset($t[$locale]); }
                    }
                    $row['_avdb_quality']=self::qualityCheckpoint($entry,array_slice($o['locales'],1));
                    Db::name('avdb_import_item')->where('item_id',$item['item_id'])->update(['translations'=>$this->json($t),'payload'=>$this->json($row),'locale_cursor'=>count($o['locales'])]);
                }
                Db::name('avdb_import_job')->where(['job_id'=>$j['job_id'],'lock_token'=>$token])->update(['phase'=>'languages']); Db::commit();
            } catch (\Throwable $e) { Db::rollback(); throw $e; } return;
        }
        // D-I15: storage check before every batch (every 20 batches in bulk mode); pause before writing.
        $this->batchNo++;
        if (!$this->bulk || $this->batchNo===1 || $this->batchNo%self::STORAGE_EVERY===0 || $this->nearTarget) {
            if (StorageGuard::refresh()['full']) { $message=StorageGuard::message() ?: 'Database or disk is full.'; $this->hold($j,$token,$o,$message); self::pauseForStorage($message); return 'held'; }
            // A2: at the storage target the job completes (before the 0.9 pause above is reached)
            if ($this->targetReached($j,$o,$token)) { return 'held'; }
        }
        if (!empty($o['download_images'])) { $items=array_slice($items,0,1); if ($this->mediaStep($j,$o,$items,$token)) { return; } }
        // Normalize the batch once (2.4 rules); refused rows fail with their message at apply.
        $norm=[]; $posters=0; $dropped=0; $hosts=[]; $allowedHosts=array_map('strtolower',array_merge((array)($this->caps($provider)['image_hosts'] ?? []),ImportProviders::extraImageHosts($provider),(array)($o['image_hosts_extra'] ?? [])));
        foreach ($items as $item) {
            $raw=$this->decode($item['payload']);
            try { $n=$this->normalizeRow($provider,$raw,$o); $norm[(int)$item['item_id']]=$n; }
            catch (\Throwable $e) { $norm[(int)$item['item_id']]=$e; continue; }
            $rawPic=is_scalar($raw['vod_pic'] ?? null) ? trim((string)$raw['vod_pic']) : '';
            if ($rawPic!=='') { $posters++; if (!isset($n['vod_pic']) || (string)$n['vod_pic']==='') { $dropped++; $h=strtolower((string)parse_url($rawPic,PHP_URL_HOST)); if ($h!=='' && count($hosts)<5) { $hosts[$h]=in_array($h,$allowedHosts,true) ? $h.' (not https)' : $h; } } }
        }
        $maxItem=max(array_map('intval',array_column($items,'item_id')));
        // 2.4: more than 5 % of the batch's posters outside the image hosts -> ask the admin.
        if ($posters>0 && $dropped/$posters>self::IMAGE_DROP_RATIO && $maxItem>(int)($o['_host_ack'] ?? 0)) {
            $o['_host_hold']=$maxItem; $o['_host_hold_hosts']=array_values(array_diff(array_keys($hosts),$allowedHosts));
            $this->hold($j,$token,$o,ImportProviders::text('image_host','The provider uses a new image host {hosts}: allow it? ({dropped} of {posters} posters in this batch). Resume to import without these images, or allow the host.',['hosts'=>implode(', ',$hosts),'dropped'=>$dropped,'posters'=>$posters]));
            return 'held';
        }
        Db::name('avdb_import_job')->where(['job_id'=>$j['job_id'],'lock_token'=>$token])->update(['phase'=>'apply']);
        // P-O1: a large bulk job writes without the four filter FULLTEXT keys (re-added when it ends)
        if ($this->bulk) { $this->ftKeysBeforeBatch($j,$o); }
        $started=microtime(true); $applied=0;
        // round 4 (flood-r4 N17): a chunk starts only when its rows x the measured seconds per row x STEP_SAFETY, plus the end-of-batch
        // work, end before the work deadline; a bulk chunk shrinks to the rows that still fit (chunkRows(), at least
        // STEP_BATCH_MIN_ROWS); the batch measures itself as it goes (a slower chunk raises the estimate of the next); when nothing
        // fits the step ends ('cap'). Before: the deadline itself was the only check, so a 50-row chunk that started just before it
        // ran past the 0.5 s reserve under the flood and its statements were refused.
        $deadline=$this->stepDeadline ?: $started+9; $spr=$this->stepSpr($o); $post=max(self::STEP_POST_S,(float)($o['_post_s'] ?? 0),(float)$this->postSec); $capped=false;
        $queue=array_values($items);
        while ($queue) {
            $nc=$this->bulk ? self::chunkRows(count($queue),$deadline-microtime(true),$spr,$post) : (self::workFits(microtime(true),$deadline,$spr*self::STEP_SAFETY+$post) ? 1 : 0);
            if ($nc<1) { $capped=true; self::trace('step_cap:chunk'); break; }
            $chunk=array_splice($queue,0,$nc);
            $tc=microtime(true); $this->inFlight=['apply',$tc,count($chunk)];
            if ($this->bulk) { $this->applyBulkChunk($j,$o,$chunk,$token,$norm); $applied+=count($chunk); }
            else {
                $item=$chunk[0];
                if ((int)$item['locale_cursor']>=count($o['locales'])) {
                    try { $this->apply($item,$o,$token,$norm[(int)$item['item_id']] ?? null); $applied++; }
                    catch (\Throwable $e) { if (self::stepFatal($e)) { throw $e; } $this->failItem($j,$token,$item,$e); }
                }
            }
            $this->inFlight=null; $this->stepUnits++; $spr=max($spr,(microtime(true)-$tc)/max(1,count($chunk)));
            foreach ([$this->prov($provider)] as $pp) { if (method_exists($pp,'pump')) { $pp->pump(); } }
        }
        // round 4 (flood-r4 N17): the end-of-batch work keeps the derived data in step with the committed rows (search queue, filter
        // vocabulary, page map, counts, the batch's rate): it runs with database time of its own when the deadline is close
        self::withDbTime(function () use ($j,$o,$provider,$applied,$started) {
        $tp=microtime(true); $this->inFlight=['post',$tp,0];
        // import-r2 N2 (CLI peak 80 MB in a 626,699-video job): maintain() records every written video, and bulk batches never
        // emptied that list, so one tools/import.php --run process kept one entry per imported video for the whole job (memory grew
        // with the job). Every batch empties it now: bulk jobs queue only the videos below their search window (updated older
        // videos, e.g. from the changes feed, need their documents refreshed); those inside the window are appended by bulkEnd.
        if ($this->touched) {
            if (!$this->bulk) { self::contract('FtIndex','touch',[array_values($this->touched)]); }
            else {
                $floor=(int)($o['_bulk_open'] ?? $o['_vod_min'] ?? 0); $below=[];
                if ($floor>0) { foreach ($this->touched as $tid) { if ((int)$tid<$floor) { $below[]=(int)$tid; } } }
                if ($below) { self::contract('FtIndex','touch',[$below]); }
            }
            // ft-vocab (fp-port): the filter vocabulary takes every video of this batch now (bulk windows included: it has no
            // window), queued and caught up in this process after the commits, so filters stay index-fast during the job
            self::contract('FilterVocab','imported',[array_values($this->touched)]);
            $this->touched=[];
        }
        // P7 / C14: rows placed below the newest page shift the deep list pages; the page map rewalks from the lowest vod_time
        if ($this->placed['min']!==null) { self::contract('ListPageMap','dirty',[(int)$this->placed['min'],array_values($this->placed['types'])]); $this->placed=['min'=>null,'types'=>[]]; }
        if ($applied) { self::contract('ScaleCounts','markDirty',[$this->bulk ? 'bulk' : 'import']); self::contract('Vod','bumpListRevision',[false]); }
        $this->postSec=microtime(true)-$tp; $this->inFlight=null;
        $this->recordRate($j,$provider,$applied,microtime(true)-$started,$this->postSec);
        });
        return $capped ? 'cap' : null;
    }
    /** Remember a placed (inserted or restored) row for ListPageMap::dirty at the batch end. */
    private function place($vodTime,$typeId,$parentId)
    {
        $t=max(0,(int)$vodTime); $this->placed['min']=$this->placed['min']===null ? $t : min($this->placed['min'],$t);
        foreach ([(int)$typeId,(int)$parentId] as $id) { if ($id>0) { $this->placed['types'][$id]=$id; } }
    }
    private function failItem($j,$token,array $item,\Throwable $e)
    {
        Db::startTrans(); try { $this->fence($j['job_id'],$token); Db::name('avdb_import_item')->where(['item_id'=>$item['item_id'],'state'=>'pending'])->update(['state'=>$this->failState($item),'message'=>$this->failureMessage($e)]); Db::commit(); } catch (\Throwable $leaseError) { Db::rollback(); throw $leaseError; }
    }
    /**
     * Round-4 (import-r4 feed loss): state of an item whose apply failed: 'failed', or for an item a change-feed row sent back to
     * pending after this job had written it (_requeue = created / updated), that earlier state: the earlier write stays in the
     * database and its rollback images stay with the item (the message tells why the newer row was not applied).
     */
    private function failState(array $item)
    {
        $r=(string)($this->decode((string)($item['payload'] ?? ''))['_requeue'] ?? '');
        return in_array($r,['created','updated'],true) ? $r : 'failed';
    }
    /**
     * Round-4 verify fix (import-r4 snapshot re-import feed loss): item $it of job $jobId (a change-feed row of the same video
     * arrived) gets the feed row as its payload and goes back to pending, so the newer row is applied last. An item this job
     * already wrote keeps its before / after images and records its state in the payload (_requeue), so its rollback still
     * restores the state before the job (or deletes the video the job created).
     */
    private function requeueItem($jobId, array $it, array $payload)
    {
        $prev=in_array($it['state'],['created','updated'],true) ? (string)$it['state'] : '';
        if ($it['state']==='pending') { $old=$this->decode((string)Db::name('avdb_import_item')->where('item_id',(int)$it['item_id'])->value('payload')); $r=(string)($old['_requeue'] ?? ''); $prev=in_array($r,['created','updated'],true) ? $r : ''; }
        if ($prev!=='') { $payload['_requeue']=$prev; } else { unset($payload['_requeue']); }
        Db::name('avdb_import_item')->where(['item_id'=>(int)$it['item_id'],'job_id'=>(string)$jobId])->update(['state'=>'pending','payload'=>$this->json($payload),'translations'=>'{}','locale_cursor'=>1,'message'=>'']);
    }
    /** D-I5: one transaction per 50 items through the same apply path; created / skipped item rows are deleted after commit. */
    private function applyBulkChunk($j,array $o,array $chunk,$token,array $norm)
    {
        $counts=[];
        $this->ftvChunk=[];
        $this->ftqChunk=[];
        \app\common\util\VodDeletion::withMediaLock(function () use ($j,$o,$chunk,$token,$norm) {
            Db::startTrans();
            try {
                $this->fence($j['job_id'],$token);
                foreach ($chunk as $item) {
                    if ((int)$item['locale_cursor']<count($o['locales'])) { continue; }
                    try { $this->applyWithMediaLock($item,$o,$token,$norm[(int)$item['item_id']] ?? null); }
                    catch (\Throwable $e) {
                        if (self::stepFatal($e)) { throw $e; }
                        Db::name('avdb_import_item')->where(['item_id'=>$item['item_id'],'state'=>'pending'])->update(['state'=>$this->failState($item),'message'=>$this->failureMessage($e)]);
                    }
                }
                // fp-port-c (P2-1): the chunk's search-queue entries (videos below its window) commit with its rows, ascending
                $ftq=array_values((array)$this->ftqChunk); $this->ftqChunk=null;
                if ($ftq) { \app\common\util\FilterVocab::searchQueue($ftq); }
                Db::commit();
            } catch (\Throwable $e) { Db::rollback(); $this->ftvChunk=null; $this->ftqChunk=null; throw $e; }
        });
        $this->ftqChunk=null;
        // fp-port-b (P2-1): the chunk's updated videos (vod_time kept) reach the vocabulary queue right after its commit
        $ftv=(array)$this->ftvChunk; $this->ftvChunk=null;
        if ($ftv) { self::contract('FilterVocab','touched',[array_values($ftv)]); }
        $ids=array_map('intval',array_column($chunk,'item_id'));
        foreach (Db::name('avdb_import_item')->where('item_id','in',$ids)->where('state','in',['created','skipped'])->field('state,count(*) n')->group('state')->select() as $c) { $counts[$c['state']]=(int)$c['n']; }
        if ($counts) {
            Db::name('avdb_import_item')->where('item_id','in',$ids)->where('state','in',['created','skipped'])->delete();
            $fresh=$this->decode(Db::name('avdb_import_job')->where('job_id',$j['job_id'])->value('options'));
            foreach ($counts as $s=>$n) { $fresh['_bulk_counts'][$s]=(int)($fresh['_bulk_counts'][$s] ?? 0)+$n; }
            Db::name('avdb_import_job')->where(['job_id'=>$j['job_id'],'lock_token'=>$token])->update(['options'=>$this->json($fresh)]);
        }
    }
    /** True when the normalized provider row would change the title or the embed URL of the stored video (D-I8b). */
    private static function visibleChange(array $n,array $v)
    {
        $plain=function ($s) { return trim(html_entity_decode(html_entity_decode((string)$s,ENT_QUOTES|ENT_HTML5,'UTF-8'),ENT_QUOTES|ENT_HTML5,'UTF-8')); };
        $urls=function ($s) { $out=[]; foreach (explode('$$$',(string)$s) as $g) { foreach (explode('#',$g) as $e) { $p=explode('$',$e,2); if (count($p)===2) { $out[]=trim($p[1]); } } } return implode('|',$out); };
        return (isset($n['vod_name']) && $plain(self::text($n['vod_name'],255))!==$plain($v['vod_name'])) || (isset($n['vod_play_url']) && $urls($n['vod_play_url'])!==$urls($v['vod_play_url']));
    }
    /**
     * D-I8b: a discovery page that would change the title or the embed URL of more than 20 % of the existing videos it
     * returned (at least 20) holds the job right after its items are queued; the admin's resume accepts them.
     * Returns the checkpoint fields to add (state, error) or [].
     */
    private function anomalyHold(array &$o,$provider,$jobId)
    {
        $changed=(int)$this->screen['changed']; $mapped=(int)$this->screen['mapped'];
        if ($changed<self::ANOMALY_MIN || $changed<=self::ANOMALY_RATIO*$mapped) { return []; }
        $max=(int)Db::name('avdb_import_item')->where('job_id',$jobId)->max('item_id');
        if ($max<=(int)($o['_anomaly_ack'] ?? 0)) { return []; }
        $o['_anomaly_hold']=$max;
        self::trace('anomaly:'.$changed.'/'.$mapped);
        return ['state'=>self::stateFor('paused',$provider),'error'=>ImportProviders::text('anomaly','This update would change the title or the player of {changed} of {mapped} existing videos (more than 20 %). The import is paused: check the provider, then resume to accept the changes.',['changed'=>$changed,'mapped'=>$mapped])];
    }
    /**
     * import-r2 N1 / N3 / N6 (pure): visible feed changes allowed within FEED_ANOMALY_WINDOW for a provider with $mapped
     * videos: min(FEED_ANOMALY_MAX, max(FEED_ANOMALY_FLOOR, FEED_ANOMALY_RATIO x $mapped)).
     */
    public static function feedAnomalyLimit($mapped)
    {
        return (int)min(self::FEED_ANOMALY_MAX, max(self::FEED_ANOMALY_FLOOR, (int)ceil(self::FEED_ANOMALY_RATIO * max(0, (int)$mapped))));
    }
    /**
     * se-R11b with the round-3 verify fix (import-r3 snapshot feed vs size bound), pure: the size bound of one discovery page.
     * $o = job options (size_bound; _bound_n = rows the job queued from the catalog listing so far), $legacyCount = rows counted
     * the old way (job items + bulk counts, hash / tombstone skips left out) for a job that started before _bound_n existed,
     * $kept = rows this page would queue, $feedPage = a change-feed page (snapshot 'changes' phase, hourly feed mode: never
     * bounded, feedAnomalyHold() guards them). Returns ['room' => ?int (rows that still fit; null = no bound on this page),
     * 'queued' => int, 'hit' => bool (this page passes the bound: queue 'room' rows, then the job pauses)].
     */
    public static function boundRoom(array $o, $legacyCount, $kept, $feedPage)
    {
        $bound = (int)($o['size_bound'] ?? 0);
        $queued = array_key_exists('_bound_n', $o) ? max(0, (int)$o['_bound_n']) : max(0, (int)$legacyCount);
        if ($bound <= 0 || $feedPage) { return ['room' => null, 'queued' => $queued, 'hit' => false]; }
        $room = max(0, $bound - $queued);
        return ['room' => $room, 'queued' => $queued, 'hit' => max(0, (int)$kept) > $room];
    }
    /** import-r2 (pure): the feed rule trips when $recent visible changes in 24 h exceed feedAnomalyLimit($mapped). */
    public static function feedAnomalyTrips($recent, $mapped)
    {
        return (int)$recent > self::feedAnomalyLimit($mapped);
    }
    /**
     * D-I8b for change-feed pages (import-r2 N1 / N3 / N6): this page's visible changes are added to the job's _feed_changed;
     * the job holds (like anomalyHold: the admin's resume accepts the changes) when this provider's visible feed changes in
     * the last FEED_ANOMALY_WINDOW (this job and the other jobs updated in that time, minus what the admin accepted) exceed
     * feedAnomalyLimit(mapped videos). The mapped count is read only above FEED_ANOMALY_FLOOR (a whole catalog has millions of
     * map rows) and kept in the job. Returns the checkpoint fields to add (state, error) or [].
     */
    private function feedAnomalyHold(array &$o,$provider,$jobId)
    {
        $changed=(int)$this->screen['changed'];
        if ($changed<=0) { return []; }
        $o['_feed_changed']=(int)($o['_feed_changed'] ?? 0)+$changed;
        $recent=max(0,(int)$o['_feed_changed']-(int)($o['_feed_acked'] ?? 0));
        try {
            foreach (Db::name('avdb_import_job')->where('provider',$provider)->where('job_id','<>',(string)$jobId)->where('updated_at','>=',time()-self::FEED_ANOMALY_WINDOW)->where('options','like','%"_feed_changed"%')->field('options')->order('updated_at desc')->limit(200)->select() as $row) {
                $x=json_decode((string)$row['options'],true);
                if (is_array($x)) { $recent+=max(0,(int)($x['_feed_changed'] ?? 0)-(int)($x['_feed_acked'] ?? 0)); }
            }
        } catch (\Throwable $e) { /* this job's own count decides */ }
        if ($recent<=self::FEED_ANOMALY_FLOOR) { return []; }
        if (!isset($o['_feed_mapped']) || time()-(int)($o['_feed_mapped_at'] ?? 0)>3600) { $o['_feed_mapped']=$this->providerVideos($provider); $o['_feed_mapped_at']=time(); }
        if (!self::feedAnomalyTrips($recent,(int)$o['_feed_mapped'])) { return []; }
        $max=(int)Db::name('avdb_import_item')->where('job_id',$jobId)->max('item_id');
        $o['_anomaly_hold']=$max; $o['_feed_hold']=1;
        $limit=self::feedAnomalyLimit((int)$o['_feed_mapped']);
        self::trace('anomaly_feed:'.$recent.'/'.$limit);
        return ['state'=>self::stateFor('paused',$provider),'error'=>ImportProviders::text('anomaly_feed','The provider changed the title or the player of {n} existing videos in 24 hours (the limit is {limit}). The import is paused: check the provider, then resume to accept the changes.',['n'=>number_format($recent),'limit'=>number_format($limit)])];
    }
    /**
     * After the first 10,000 items of a job the site's own rolling rate replaces the 8 ms constant (2.2). Round 4 (flood-r4 N17):
     * the batch's seconds per row (_spr) and end-of-batch seconds (_post_s) feed the step admission (half weight per batch, so a
     * flood shows within two batches), and a batch that completes clears the cut counter (_db_cuts).
     */
    private function recordRate($j,$provider,$applied,$seconds,$post=null)
    {
        if ($applied<10 || $seconds<=0) { return; }
        $this->lastSpr=$seconds/$applied;
        $o=$this->decode(Db::name('avdb_import_job')->where('job_id',$j['job_id'])->value('options'));
        $o['_rate_n']=(int)($o['_rate_n'] ?? 0)+$applied; $o['_rate_s']=round((float)($o['_rate_s'] ?? 0)+$seconds,3);
        $o['_spr']=round(isset($o['_spr']) ? 0.5*(float)$o['_spr']+0.5*$this->lastSpr : $this->lastSpr,5);
        if ($post!==null) { $o['_post_s']=round(isset($o['_post_s']) ? 0.5*(float)$o['_post_s']+0.5*(float)$post : (float)$post,3); }
        unset($o['_db_cuts']);
        Db::name('avdb_import_job')->where('job_id',$j['job_id'])->update(['options'=>$this->json($o)]);
        if ($o['_rate_n']>=ImportProviders::OWN_RATE_AFTER) { try { $p=$this->prov($provider); if (method_exists($p,'pacer') && $p->pacer()) { $p->pacer()->setRate($o['_rate_n']/max(0.001,$o['_rate_s'])); } } catch (\Throwable $e) { /* informational */ } }
    }
    private function imageEligible(array $row,array $o,$remote,$provider=self::PROVIDER)
    {
        $map=Db::name('avdb_import_map')->where(['provider'=>$provider,'remote_id'=>$remote])->find(); $local=$map['vod_id'] ?? ($o['existing_links'][$remote] ?? 0);
        if (!$local && !empty($o['existing_links'][$remote])) { $local=$o['existing_links'][$remote]; }
        $old=$local ? Db::name('vod')->where('vod_id',$local)->find() : null;
        if ($old && !empty($o['existing_links'][$remote])) { if ($map && $map['vod_id'] && (int)$map['vod_id']!==(int)$o['existing_links'][$remote]) { return false; } if (!self::matches($old,self::codes($row))) { return false; } $matches=Db::name('vod')->where('vod_sub|vod_remarks','in',self::codes($row))->select(); $matches=array_filter($matches,function($v) use($row){ return self::matches($v,self::codes($row)); }); if (count($matches)!==1) { return false; } }
        if ($old) { return empty($old['vod_lock']) && empty($old['vod_recycle_time']) && ($o['existing']==='smart' || ($o['existing']==='update' && in_array('images',$o['update_fields'],true))); }
        if ($local) { return false; }
        $codes=self::dedupeCodes($row,$this->caps($provider));
        return !$codes || !Db::name('vod')->where('vod_sub|vod_remarks','in',$codes)->find();
    }
    private function mediaStep($j,$o,$items,$token)
    {
        return \app\common\util\VodDeletion::withMediaLock(function () use ($j,$o,$items,$token) { return $this->mediaStepWithMediaLock($j,$o,$items,$token); });
    }

    private function mediaStepWithMediaLock($j,$o,$items,$token)
    {
        $provider=self::jobProvider($j);
        foreach ($items as $item) {
            if ((int)$item['locale_cursor']<count($o['locales'])) { continue; }
            $field=null; $url=null;
            Db::startTrans(); try {
                $this->fence($j['job_id'],$token); $current=Db::name('avdb_import_item')->where('item_id',$item['item_id'])->lock(true)->find();
                if (!$current || $current['state']!=='pending') { Db::commit(); continue; }
                $row=$this->normalizeRow($provider,$this->decode($current['payload']),$o);
                if (!$this->imageEligible($row,$o,(int)$current['remote_id'],$provider)) { Db::commit(); continue; }
                $clean=self::sanitizeRow($row,$o,$this->activePlayers());
                $journal=is_array($row['_avdb_images'] ?? null) ? $row['_avdb_images'] : [];
                foreach (['vod_pic','vod_pic_thumb','vod_pic_slide'] as $key) { if (!empty($clean[$key]) && empty($journal[$key])) { $field=$key; $url=$clean[$key]; break; } }
                if ($field===null) { Db::commit(); continue; }
                Db::name('avdb_import_job')->where(['job_id'=>$j['job_id'],'lock_token'=>$token])->update(['phase'=>'images']); Db::commit();
                // Network and filesystem work stays outside DB locks. One cover
                // image per request; successful shared cache files are never undone.
                if ($this->images===null) { $this->images=new \app\common\util\AvdbImages(); }
                $path=$this->images->download($url,(int)$current['remote_id']);
                if (!is_string($path) || !preg_match('/^upload\/vod\/avdb\/[a-f0-9]{64}\.(?:jpg|png|gif|webp)$/D',$path)) { throw new \RuntimeException('Image downloader returned an invalid local asset path.'); }
                Db::startTrans(); $this->fence($j['job_id'],$token); $latest=Db::name('avdb_import_item')->where('item_id',$item['item_id'])->lock(true)->find();
                if ($latest && $latest['state']==='pending') { $payload=$this->decode($latest['payload']); $normalized=$this->normalizeRow($provider,$payload,$o); if ($this->imageEligible($normalized,$o,(int)$latest['remote_id'],$provider) && ($normalized[$field] ?? null)===$url) { $payload['_avdb_images'][$field]=$path; Db::name('avdb_import_item')->where('item_id',$item['item_id'])->update(['payload'=>$this->json($payload)]); } }
                Db::commit(); return true;
            } catch (\Throwable $e) {
                Db::rollback(); if ($e instanceof AvdbImportLeaseException || self::isDbTimeout($e)) { throw $e; }
                Db::startTrans(); try { $this->fence($j['job_id'],$token); Db::name('avdb_import_item')->where(['item_id'=>$item['item_id'],'state'=>'pending'])->update(['state'=>$this->failState($item),'message'=>$this->failureMessage($e)]); Db::commit(); } catch (\Throwable $leaseError) { Db::rollback(); throw $leaseError; } return true;
            }
        }
        return false;
    }
    /**
     * Provider text is stored in the single-escaped form the list API delivers ("Don&#039;t", "A &amp; B").
     * The bulk export used by full-catalog jobs delivered one more level ("Don&amp;#039;t", "A &amp;amp; B"),
     * which every page then showed as "&#039;" / "&amp;". Collapse entity sequences escaped twice or more.
     */
    public static function singleEscaped($s)
    {
        $s = (string)$s;
        for ($i = 0; $i < 4 && strpos($s, '&amp;') !== false; $i++) {
            $next = preg_replace('/&amp;(amp|quot|lt|gt|apos|nbsp|#[0-9]{1,7}|#x[0-9a-fA-F]{1,6});/', '&$1;', $s);
            if (!is_string($next) || $next === $s) { break; }
            $s = $next;
        }
        return $s;
    }
    /**
     * vod_blurb as plain text that VodValidate::formatDataBeforeDb (mac_filter_xss = strip_tags + htmlspecialchars, then
     * mb_substr to $max) stores whole: every entity decoded once (also "&#039;" without "&amp;", which was escaped twice),
     * a broken entity at the end of the provider text dropped, and cut on a character boundary so the escaped form is
     * at most $max characters long. Never ends in a partial entity.
     */
    public static function blurbPlain($s, $max = 255)
    {
        $s = mb_convert_encoding((string)$s, 'UTF-8', 'UTF-8');
        $s = trim(strip_tags(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $s = rtrim((string)preg_replace('/&(?:#x?[0-9a-f]{0,7}|am|amp|ap|apo|apos|qu|quo|quot|lt|gt|nb|nbs|nbsp)$/i', '', $s));
        if (mb_strlen(htmlspecialchars($s, ENT_QUOTES, 'UTF-8'), 'UTF-8') <= $max) { return $s; }
        $out = ''; $len = 0;
        foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $w = mb_strlen(htmlspecialchars($ch, ENT_QUOTES, 'UTF-8'), 'UTF-8');
            if ($len + $w > $max) { break; }
            $out .= $ch; $len += $w;
        }
        return rtrim($out);
    }
    private static function text($v,$length=65535) { if (!is_scalar($v)) { return ''; } return mb_substr(trim(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/','',self::singleEscaped(strip_tags((string)$v)))),0,$length); }
    public static function translation(array $row) { $r=[]; foreach (['vod_name','vod_en','vod_blurb','vod_content','vod_class','vod_tag','vod_area','vod_state','vod_actor','vod_director','vod_writer'] as $key) { if (isset($row[$key]) && self::text($row[$key])!=='') { $r[$key]=self::text($row[$key],$key==='vod_content'?65535:255); } } return $r; }
    private static function qualityCheckpoint(array $entry,array $locales)
    {
        $out=['rejected_locales'=>[],'unavailable_locales'=>[],'quality_reasons'=>[],'rejected_title_sha256'=>[]];
        foreach ($locales as $locale) {
            foreach (['rejected_locales','quality_reasons'] as $field) { if (isset($entry[$field][$locale]) && is_scalar($entry[$field][$locale])) { $out[$field][$locale]=self::text($entry[$field][$locale],255); } }
            $hash=$entry['rejected_title_sha256'][$locale] ?? null; if (is_string($hash) && preg_match('/^[a-f0-9]{64}$/D',$hash)) { $out['rejected_title_sha256'][$locale]=$hash; }
            if (in_array($locale,(array)($entry['unavailable_locales'] ?? []),true)) { $out['unavailable_locales'][]=$locale; }
        }
        return $out;
    }
    private static function genuineTranslation(array $payload,$locale,array $source)
    {
        $clean=self::translation($payload);
        // The bundle classifies availability; this boundary independently bounds
        // scalar fields without guessing a language from names, codes or quotes.
        if (empty($clean['vod_name'])) { unset($clean['vod_en']); }
        if (empty($clean['vod_name']) && empty($clean['vod_content']) && empty($clean['vod_blurb'])) { return []; }
        return $clean;
    }
    private static function url($v) { if (!is_scalar($v) || preg_match('/[\x00-\x20\x7f\"\'<>`]/',(string)$v)) { throw new \RuntimeException('Unsafe characters in media URL.'); } $v=(string)$v; if (!filter_var($v,FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($v,PHP_URL_SCHEME)),['https','http'],true) || parse_url($v,PHP_URL_USER)!==null || strpos($v,'#')!==false || strpos($v,'$')!==false) { throw new \RuntimeException('Unsafe or unsupported media URL.'); } return $v; }
    public static function sanitizeRow(array $row,array $o,array $players, &$imageWarnings = null)
    {
        $imageWarnings=[];
        $r=[]; foreach (self::GROUPS as $group=>$fields) { if ($group==='playback') { continue; } foreach ($fields as $key) { if (isset($row[$key])) { $r[$key]=self::text($row[$key],$key==='vod_content'?65535:($key==='vod_pic_screenshot'?65535:1024)); } } }
        // F11: vod_blurb is HTML-escaped again by VodValidate::formatDataBeforeDb (mac_filter_xss), so decode the provider's
        // entities once here; otherwise "A &amp; B" was stored as "A &amp;amp; B" and shown as "&amp;".
        // Function r3 P3-2: formatDataBeforeDb also cuts vod_blurb to 255 characters AFTER escaping, which left a broken
        // entity ("I can&#039") at the end. blurbPlain() decodes fully and cuts so that the escaped text fits.
        if (isset($r['vod_blurb'])) { $r['vod_blurb'] = self::blurbPlain($r['vod_blurb'], 255); }
        foreach (['vod_total'=>16777215,'vod_isend'=>1] as $key=>$maximum) { if (isset($row[$key])) { if (!is_scalar($row[$key]) || !preg_match('/^[0-9]+$/D',(string)$row[$key]) || (float)$row[$key]>$maximum) { throw new \RuntimeException('Invalid provider episode count or completion flag.'); } $r[$key]=(int)$row[$key]; } }
        // Invalid optional images are omitted, preserving existing values.
        foreach (['vod_pic','vod_pic_thumb','vod_pic_slide'] as $key) {
            if (isset($row[$key]) && (!is_scalar($row[$key]) || (string)$row[$key]!=='')) {
                try { $value=self::url($row[$key]); if (strlen($value)>1024) { throw new \RuntimeException('Optional image URL exceeds local limits.'); } $r[$key]=$value; }
                catch (\RuntimeException $e) { unset($r[$key]); $imageWarnings[$key]=1; }
            }
        }
        if (isset($row['vod_pic_screenshot']) && (!is_scalar($row['vod_pic_screenshot']) || (string)$row['vod_pic_screenshot']!=='')) {
            $shots=[]; $shotBytes=0;
            if (!is_scalar($row['vod_pic_screenshot'])) { $imageWarnings['vod_pic_screenshot']=1; }
            else { foreach (explode('#',(string)$row['vod_pic_screenshot']) as $shot) {
                try { $value=self::url($shot); if ($shotBytes + ($shots ? 1 : 0) + strlen($value)>65535) { throw new \RuntimeException('Optional screenshots exceed local limits.'); } $shotBytes+=($shots ? 1 : 0)+strlen($value); $shots[]=$value; }
                catch (\RuntimeException $e) { $imageWarnings['vod_pic_screenshot']=($imageWarnings['vod_pic_screenshot'] ?? 0)+1; }
            } }
            if ($shots) { $r['vod_pic_screenshot']=implode('#',$shots); }
            else { unset($r['vod_pic_screenshot']); }
        }
        $remoteType=(string)($row['type_id'] ?? ''); if (!isset($o['category_map'][$remoteType])) { throw new \RuntimeException('Map provider category '.$remoteType.' before importing this video.'); } $r['type_id']=(int)$o['category_map'][$remoteType];
        $sources=explode('$$$',(string)($row['vod_play_from'] ?? '')); $urls=explode('$$$',(string)($row['vod_play_url'] ?? ''));
        if (count($sources)!==count($urls) || !$sources || $sources[0]==='') { throw new \RuntimeException('Playback groups are missing or misaligned.'); }
        $mapped=[]; $clean=[]; if (count($sources)>20) { throw new \RuntimeException('Too many playback source groups.'); }
        foreach ($sources as $i=>$source) { $target=$o['player_map'][$source] ?? ''; if (!$target && !empty($o['default_player'])) { $target=in_array($source,$players,true)?$source:$o['default_player']; } if (!$target || !in_array($target,$players,true)) { throw new \RuntimeException('Map playback source "'.$source.'" to an enabled local player.'); } $mapped[]=$target; $episodes=[]; foreach (explode('#',$urls[$i]) as $episode) { $parts=explode('$',$episode,2); if (count($parts)!==2) { throw new \RuntimeException('Invalid episode label/URL.'); } $label=str_replace(['#','$'],['',''],self::text($parts[0],100)); $episodes[]=$label.'$'.self::url($parts[1]); } $clean[]=implode('#',$episodes); }
        $r['vod_play_from']=implode('$$$',$mapped); $r['vod_play_url']=implode('$$$',$clean); $r['vod_play_server']=implode('$$$',array_fill(0,count($mapped),'no')); $r['vod_play_note']=implode('$$$',array_fill(0,count($mapped),''));
        if (strlen($r['vod_play_from'])>255 || strlen($r['vod_play_server'])>255 || strlen($r['vod_play_note'])>255) { throw new \RuntimeException('Mapped playback source fields exceed local limits.'); }
        $r['vod_sub']=self::text($row['vod_sub'] ?? '',255); $r['vod_status']=$o['publish']; return $r;
    }
    private static function imageWarningMessage($message,array $warnings)
    {
        if (!$warnings) { return $message; }
        $fields=[]; foreach ($warnings as $field=>$count) { $fields[]=$field.':'.(int)$count; }
        return mb_substr(trim($message.' Warning: ignored invalid optional image values ('.implode(', ',$fields).').'),0,255,'UTF-8');
    }
    private function activePlayers()
    {
        if ($this->playerKeys===null) { $this->playerKeys=[]; foreach ((array)config('vodplayer') as $key=>$player) { if (!empty($player['status'])) { $this->playerKeys[]=$key; } } } return $this->playerKeys;
    }
    private function flushCatalog()
    {
        // Bulk jobs (D-I5): no list-cache clear per batch (the revision bump of C9 runs at most every 5 minutes), no IndexNow.
        if ($this->bulk) { $this->catalogDirty=false; $this->seoPingIds=[]; return; }
        if ($this->catalogDirty) { (new Vod())->clearListCache(); $this->catalogDirty=false; }
        // End of a batch: queue the saved videos for IndexNow and send when due (no-op while IndexNow is off); never breaks the import.
        if ($this->seoPingIds) { $seoPingBatch=array_values($this->seoPingIds); $this->seoPingIds=[]; try { if (class_exists('\app\common\util\SeoPing')) { \app\common\util\SeoPing::queueVods($seoPingBatch); \app\common\util\SeoPing::flush(); } } catch (\Throwable $e) { error_log('[AVDB] IndexNow: '.get_class($e)); } }
    }
    /**
     * fp-port-c (FINISH-PORT-REVIEW-b P2-1): the search-queue entries of written / restored / hidden videos inside the CURRENT
     * transaction (FilterVocab::searchQueue: throws, so the item fails instead of committing without its entry). A bulk job's
     * videos inside its search window are left to the window's append (bulkEnd), as at the batch end; inside a bulk chunk the
     * ids are collected and written once, ascending, right before the chunk's commit (applyBulkChunk).
     */
    private function ftQueueTx(array $ids,array $o = [])
    {
        if (!class_exists('\app\common\util\FilterVocab') || !method_exists('\app\common\util\FilterVocab','searchQueue')) { return; }
        if ($this->bulk) {
            $floor=(int)($o['_bulk_open'] ?? $o['_vod_min'] ?? 0); $keep=[];
            foreach ($ids as $qid) { if ($floor<=0 || (int)$qid<$floor) { $keep[]=(int)$qid; } }
            $ids=$keep;
        }
        if (!$ids) { return; }
        if ($this->ftqChunk!==null) { foreach ($ids as $qid) { $this->ftqChunk[(int)$qid]=(int)$qid; } return; }
        \app\common\util\FilterVocab::searchQueue($ids);
    }
    private function maintain($row,array $old = [])
    {
        $this->catalogDirty=true;
        $id=(int)($row['vod_id'] ?? 0); if ($id>0) { $this->touched[$id]=$id; }
        // fp-port-b (FINISH-PORT-REVIEW P2-1): the filter vocabulary queues every written video right after its commit (as the
        // live package does), so a batch cut short (runner killed, timeout, restart, lease lost) leaves no written video
        // unqueued; the batch end still catches up in this process (imported()). A bulk chunk queues its updated videos after
        // the chunk's commit (applyBulkChunk; its new ids lie above the vocabulary's id floor)
        if ($id>0) { if ($this->ftvChunk!==null) { if ($old) { $this->ftvChunk[$id]=$id; } } else { self::contract('FilterVocab','touched',[[$id]]); } }
        if ($this->bulk) { if ($old) { try { (new Vod())->invalidateDetailCache($old); } catch (\Throwable $e) { error_log('[AVDB] Cache maintenance: '.$e->getMessage()); } } return; }
        $seoPingId=$id; if ($seoPingId>0) { $this->seoPingIds[$seoPingId]=$seoPingId; }
        try { $m=new Vod(); $m->invalidateDetailCache($old); $m->invalidateDetailCache($row); $search=new VodSearch(); if ($search->isFrontendEnabled()) { $search->checkAndUpdateTopResults($row); } } catch (\Throwable $e) { error_log('[AVDB] Cache/search maintenance: '.$e->getMessage()); }
    }
    private function apply($item,$o,$token,$norm=null)
    {
        return \app\common\util\VodDeletion::withMediaLock(function () use ($item,$o,$token,$norm) { return $this->applyWithMediaLock($item,$o,$token,$norm); });
    }
    /** Map row bookkeeping after a write or an unchanged check (D-I4 row_hash, D-I8 seen_at, D-I9 state). */
    private function mapSeen($provider,$remote,array $row,array $extra = [])
    {
        $set=['seen_at'=>$this->now(),'missed_at'=>0]+$extra;
        if (isset($row['_row_hash']) && is_string($row['_row_hash']) && preg_match('/^[a-f0-9]{40}$/D',$row['_row_hash'])) { $set['row_hash']=$row['_row_hash']; }
        // round 4 (import-r4 snapshot re-import): the provider's time of the row the video now holds (screenRows() never lets an older row replace it)
        if ($row) { $t=self::rowTime($row); if ($t>0) { $set['row_time']=$t; } }
        Db::name('avdb_import_map')->where(['provider'=>$provider,'remote_id'=>(int)$remote])->update($set);
    }
    /**
     * Round-4 verify fix (import-r4, pure): the provider's time of a normalized row (vod_time: unix seconds from XvidClient, or the
     * date-time string of other providers, read as UTC like XvidClient::remoteTime), 0 when unknown.
     */
    public static function rowTime(array $row)
    {
        $v=$row['vod_time'] ?? null;
        if (is_int($v)) { return $v>0 && $v<=2147483647 ? $v : 0; }
        return is_scalar($v) ? XvidClient::remoteTime($v,time()+86400) : 0;
    }

    private function applyWithMediaLock($item,$o,$token,$norm=null)
    {
        $provider=(string)($o['provider'] ?? self::PROVIDER); if (!ImportProviders::isKey($provider)) { $provider=self::PROVIDER; }
        $caps=$this->caps($provider);
        Db::startTrans(); try {
            $this->fence($item['job_id'],$token); $current=Db::name('avdb_import_item')->where('item_id',$item['item_id'])->lock(true)->find();
            if (!$current || $current['state']!=='pending') { Db::commit(); return; } $item=$current;
            if ($norm instanceof \Throwable) { throw $norm; }
            $row=is_array($norm) ? $norm : $this->normalizeRow($provider,$this->decode($item['payload']),$o);
            // round 4 (import-r4 feed loss): a change-feed row sent this item back after this job wrote it: the item keeps that state
            // (and its rollback images) whatever this apply does
            $keep=in_array((string)($row['_requeue'] ?? ''),['created','updated'],true) ? (string)$row['_requeue'] : '';
            $imageWarnings=[]; $data=self::sanitizeRow($row,$o,$this->activePlayers(),$imageWarnings);
            foreach (self::EXTRA_FIELDS as $field=>$max) { if (isset($row['_extra'][$field]) || ($provider!==self::PROVIDER && isset($row[$field]) && is_scalar($row[$field]))) { $data[$field]=mb_substr(self::text($row['_extra'][$field] ?? $row[$field],$max),0,$max,'UTF-8'); } }
            if (!empty($o['download_images'])) { foreach (['vod_pic','vod_pic_thumb','vod_pic_slide'] as $key) { $local=$row['_avdb_images'][$key] ?? null; if ($local!==null && array_key_exists($key,$data)) { if (!is_string($local) || !preg_match('/^upload\/vod\/avdb\/[a-f0-9]{64}\.(?:jpg|png|gif|webp)$/D',$local)) { throw new \RuntimeException('Invalid downloaded image checkpoint.'); } $data[$key]=$local; } } }
            $type=Db::name('type')->where(['type_id'=>$data['type_id'],'type_mid'=>1])->find(); if (!$type) { throw new \RuntimeException('Local category is no longer valid.'); } $data['type_id_1']=(int)$type['type_pid'];
            // Lock the provider identity even across different concurrent jobs.
            try { Db::name('avdb_import_map')->insert(['provider'=>$provider,'remote_id'=>(int)$item['remote_id'],'vod_id'=>0]); } catch (\Throwable $e) { if (stripos($e->getMessage(),'Duplicate')===false && strpos($e->getMessage(),'1062')===false) { throw $e; } }
            $map=Db::name('avdb_import_map')->where(['provider'=>$provider,'remote_id'=>(int)$item['remote_id']])->lock(true)->find();
            // D-I9: a tombstone (deleted by an administrator) is never re-added unless a selected import overrides it.
            $tomb=(int)($map['state'] ?? 0)===2 && !(int)$map['vod_id'];
            if ($tomb && empty($o['override_tombstones'])) { Db::name('avdb_import_item')->where('item_id',$item['item_id'])->update(['state'=>$keep ?: 'skipped','payload'=>'','translations'=>'','message'=>ImportProviders::text('tombstone_skip','Deleted by an administrator earlier; not imported again.')]); Db::commit(); return; }
            $old=$map['vod_id'] ? Db::name('vod')->where('vod_id',$map['vod_id'])->lock(true)->find() : null;
            $adopted=false; $link=$o['existing_links'][(int)$item['remote_id']] ?? 0;
            if ($link && $map['vod_id'] && (int)$map['vod_id']!==$link) { throw new \RuntimeException('This provider identity is already mapped to another local video.'); }
            if ($link && !$map['vod_id']) {
                $codes=self::codes($row); $matches=$codes ? Db::name('vod')->where('vod_sub|vod_remarks','in',$codes)->lock(true)->select() : [];
                // SQL collation can be case insensitive; require exact canonical codes in PHP too.
                $matches=array_values(array_filter($matches,function($v) use($codes){return self::matches($v,$codes);}));
                if (count($matches)!==1 || (int)$matches[0]['vod_id']!==$link) { throw new \RuntimeException('Existing link is ambiguous or no longer matches the exact movie code.'); }
                $old=$matches[0];
                if (Db::name('avdb_import_map')->where(['provider'=>$provider,'vod_id'=>$link,'remote_id'=>['neq',(int)$item['remote_id']]])->lock(true)->find()) { throw new \RuntimeException('Local video is already linked to another AVDB identity.'); }
                $adopted=true;
            }
            if ($map['vod_id'] && !$old) { throw new \RuntimeException('Mapped video is missing; explicit relinking is required.'); }
            // 2.5 de-duplication, one policy (`skip`): the same JAV code as a video of another provider maps this row to
            // that video and changes nothing.
            if (!$old && !$link) {
                $codes=self::dedupeCodes($row,$caps);
                if ($codes) {
                    $matches=array_values(array_filter(Db::name('vod')->where('vod_sub|vod_remarks','in',$codes)->field('vod_id,vod_sub,vod_remarks')->lock(true)->select(),function($v) use($codes){return self::matches($v,$codes);}));
                    if ($matches) {
                        $hit=(int)$matches[0]['vod_id'];
                        $otherProvider=(bool)Db::name('avdb_import_map')->where('vod_id',$hit)->where('provider','<>',$provider)->field('remote_id')->find();
                        $sameProvider=(bool)Db::name('avdb_import_map')->where(['vod_id'=>$hit,'provider'=>$provider])->field('remote_id')->find();
                        if (empty($caps['jav_codes']) || ($otherProvider && !$sameProvider)) {
                            Db::name('avdb_import_map')->where(['provider'=>$provider,'remote_id'=>(int)$item['remote_id']])->update(['vod_id'=>$hit,'state'=>0]);
                            $this->mapSeen($provider,(int)$item['remote_id'],$row);
                            Db::name('avdb_import_item')->where('item_id',$item['item_id'])->update(['vod_id'=>$hit,'state'=>$keep ?: 'skipped','payload'=>'','translations'=>'','message'=>ImportProviders::text('dedupe_skip','Same movie code as an existing video ({code}): linked to it, nothing changed.',['code'=>mb_substr($codes[0],0,40,'UTF-8')])]);
                            Db::commit(); return;
                        }
                    }
                }
            }
            if ($adopted && empty($old['vod_lock']) && empty($old['vod_recycle_time'])) { Db::name('avdb_import_map')->where(['provider'=>$provider,'remote_id'=>(int)$item['remote_id']])->update(['vod_id'=>$link]); }
            if ($old && (!empty($old['vod_lock']) || !empty($old['vod_recycle_time']) || $o['existing']==='skip')) { $this->mapSeen($provider,(int)$item['remote_id'],[]); Db::name('avdb_import_item')->where('item_id',$item['item_id'])->update(['vod_id'=>$old['vod_id'],'state'=>$keep ?: 'skipped','payload'=>'','translations'=>'','message'=>self::imageWarningMessage($adopted && empty($old['vod_lock']) && empty($old['vod_recycle_time'])?'Linked the existing video; existing metadata preserved.':'Existing video skipped (policy, lock or recycle).',$imageWarnings)]); Db::commit(); return; }
            $restore=$old && (int)($map['state'] ?? 0)===1;
            if ($old) { $allowed=[]; foreach ($o['existing']==='smart' ? ['text','metadata','playback','images'] : $o['update_fields'] as $group) { foreach (self::GROUPS[$group] ?? [] as $key) { $allowed[$key]=true; } } foreach (array_keys(self::EXTRA_FIELDS) as $field) { if (isset($data[$field]) && ($o['existing']==='smart' || in_array('metadata',$o['update_fields'],true))) { $allowed[$field]=true; } } $data=array_intersect_key($data,$allowed); unset($data['vod_en']); $id=(int)$old['vod_id'];
                if ($o['existing']==='smart') { if (empty($o['download_images'])) { foreach (self::GROUPS['images'] as $field) { if (!empty($old[$field]) && !preg_match('~^https?://~i',$old[$field])) { unset($data[$field]); } } } }
                // D-I8: a video the presence sweep hid (gone at the provider) is back: visible again.
                if ($restore) { $data['vod_status']=1; }
             }
            else {
                $data['vod_content']=$data['vod_content'] ?? ''; $data['vod_letter']=mb_substr(mb_strtoupper(mb_substr($data['vod_en'] ?? $data['vod_name'],0,1,'UTF-8'),'UTF-8'),0,1,'UTF-8');
                if (!empty($caps['jav_codes']) && self::codes($row) && Db::name('vod')->where('vod_sub|vod_remarks','in',self::codes($row))->find()) { throw new \RuntimeException('This movie code already exists without an AVDB identity; explicit linking is required.'); }
                // D-I12 placement: as new arrivals (job start - rank, upload date in vod_pubdate) or the provider's upload time.
                $now=$this->now(); $data['vod_time_add']=$now;
                $remoteTime=is_int($row['vod_time'] ?? null) ? (int)$row['vod_time'] : XvidClient::remoteTime($row['vod_time'] ?? '', $now);
                if (!empty($o['_place']['as_new'])) {
                    $data['vod_time']=max(1,(int)$o['_place']['start']-max(0,(int)($row['_rank'] ?? 0)));
                    if ($remoteTime) { $data['vod_pubdate']=gmdate('Y-m-d',$remoteTime); }
                } else { $data['vod_time']=$remoteTime ?: $now; }
            }
            // D-P6: provider marker on new and refreshed rows.
            $data['vod_reurl']=$provider.':'.(int)$item['remote_id'];
            $validate=new VodValidate(); if (!$validate->check($old ? array_merge($old,$data) : $data)) { throw new \RuntimeException('Video validation failed: '.$validate->getError()); } $data=VodValidate::formatDataBeforeDb($data);
            $before=['vod'=>$old,'translations'=>[],'adopted'=>$adopted]; $writeTranslations=(!$old || $o['existing']==='smart' || in_array('translations',$o['update_fields'],true)) && count($o['locales'])>1;
            if (!$writeTranslations && (!$old || $o['existing']==='smart' || in_array('translations',$o['update_fields'],true)) && !empty($caps['translations'])) { $writeTranslations=true; }
            $existingTranslations=[]; if ($old && $writeTranslations) { $before['translations']=Db::name('avdb_translation')->where('vod_id',$id)->select(); foreach ($before['translations'] as $record) { $existingTranslations[$record['locale']]=$record; } }
            if ($old) { foreach ($data as $key=>$value) { if (isset($old[$key]) && (string)$old[$key]===(string)$value) { unset($data[$key]); } } if ($data) { Db::name('vod')->where('vod_id',$id)->update($data); } }
            else { $id=(int)Db::name('vod')->insertGetId($data); Db::name('avdb_import_map')->where(['provider'=>$provider,'remote_id'=>(int)$item['remote_id']])->update(['vod_id'=>$id,'state'=>0]); $this->place($data['vod_time'] ?? 0,$data['type_id'] ?? 0,$data['type_id_1'] ?? 0); $this->insertedRows++; }
            if ($restore) { Db::name('avdb_import_map')->where(['provider'=>$provider,'remote_id'=>(int)$item['remote_id']])->update(['state'=>0]); $this->place($old['vod_time'] ?? 0,$old['type_id'] ?? 0,$old['type_id_1'] ?? 0); }
            $changed=!$old || (bool)$data || $adopted;
            if ($writeTranslations) {
                $incoming=$this->decode($item['translations']);
                foreach (array_slice($o['locales'],1) as $locale) {
                    $payload=self::genuineTranslation((array)($incoming[$locale] ?? []),$locale,$row); $exists=$existingTranslations[$locale] ?? null;
                    $previous=$exists ? $this->decode($exists['payload']) : [];
                    $hash=$row['_avdb_quality']['rejected_title_sha256'][$locale] ?? ($row['_avdb_quality']['rejected_locales'][$locale]['rejected_title_sha256'] ?? null);
                    if ($old && $o['existing']==='smart' && $exists && is_string($hash) && preg_match('/^[a-f0-9]{64}$/D',$hash) && isset($previous['vod_name']) && hash('sha256',self::text($previous['vod_name'],255))===$hash) {
                        $remaining=$previous; unset($remaining['vod_name']);
                        // Localized presentation text may have been manually edited.
                        // Only the same rejected copied original is repairable.
                        if (isset($remaining['vod_en']) && hash('sha256',self::text($remaining['vod_en'],255))===$hash) { unset($remaining['vod_en']); }
                        $meaningful=self::translation($remaining);
                        foreach (['vod_content','vod_blurb'] as $field) { if (!empty($meaningful[$field]) && $meaningful[$field]===self::text($row[$field] ?? '')) { unset($meaningful[$field]); } }
                        if (empty($meaningful['vod_content']) && empty($meaningful['vod_blurb']) && empty($meaningful['vod_en']) && empty($meaningful['vod_actor']) && empty($meaningful['vod_director']) && empty($meaningful['vod_writer'])) { $meaningful=[]; }
                        if (!$meaningful) { $previous=[]; } else { $previous=$meaningful; }
                    }
                    // Missing source languages preserve genuine translations and manual fields.
                    $merged=array_merge($previous,$payload);
                    if (!$merged) { if ($exists && $previous!==$this->decode($exists['payload'])) { Db::name('avdb_translation')->where(['vod_id'=>$id,'locale'=>$locale])->delete(); $changed=true; } continue; }
                    $merged=self::translation($merged);
                    if ($exists && $merged==$this->decode($exists['payload'])) { continue; }
                    $record=['vod_id'=>$id,'locale'=>$locale,'payload'=>$this->json($merged),'updated_at'=>time()];
                    if ($exists) { Db::name('avdb_translation')->where(['vod_id'=>$id,'locale'=>$locale])->update($record); } else { Db::name('avdb_translation')->insert($record); } $changed=true;
                }
            }
            $this->mapSeen($provider,(int)$item['remote_id'],$row);
            // Journal compaction: a written or skipped item no longer needs its provider payload or translations copy.
            if (!$changed) { Db::name('avdb_import_item')->where('item_id',$item['item_id'])->update(['vod_id'=>$id,'state'=>$keep ?: 'skipped','payload'=>'','translations'=>'','message'=>self::imageWarningMessage('Unchanged: provider information and available translations already match.',$imageWarnings)]); Db::commit(); return; }
            // After image = fingerprint of the written state (rollback compares it); before image only where rollback restores something.
            // fp-port-c (FINISH-PORT-REVIEW-b P2-1): the written video (a restore of a video the presence sweep hid included) is
            // queued for the search table in THIS transaction, so it is never visible without a search document or a queue entry
            // when the runner is cut after the commit (killed, timeout, restart, lease lost); the batch-end touch stays
            $this->ftQueueTx([$id],$o);
            $fresh=Db::name('vod')->where('vod_id',$id)->find(); $after=['v'=>2,'vod'=>self::stateHash($fresh),'translations'=>$writeTranslations?self::stateHash(Db::name('avdb_translation')->where('vod_id',$id)->select(),true):null];
            Db::name('avdb_import_item')->where('item_id',$item['item_id'])->update(['vod_id'=>$id,'state'=>$keep==='created' ? 'created' : ($old?'updated':'created'),'payload'=>'','translations'=>'','before_image'=>$keep==='created' ? '' : ($keep==='updated' ? (string)$item['before_image'] : ($old?$this->json($before):'')),'after_image'=>$this->json($after),'message'=>self::imageWarningMessage('',$imageWarnings)]); Db::commit(); $this->maintain($fresh,$old ?: []);
        } catch (\Throwable $e) { Db::rollback(); throw $e; }
    }

    // ------------------------------------------------------------------ presence sweep (D-I8)

    /**
     * One presence window. Sweep: present ids get seen_at (and a video hidden by an earlier recheck is restored);
     * missing ids get missed_at (first miss, nothing hidden). Recheck (>= 24 h after the first miss): ids still missing
     * are collected; at the end the breaker (> 2 % of the provider's videos or > 5,000) aborts with a notice, otherwise
     * they are hidden (map.state 1, vod_status 0, edge purge). A window that answers 0 rows where the previous sweep
     * saw >= 50 % present aborts the run (nothing hidden). Returns true when the job finished.
     */
    private function presenceStep($j,array $o,$token)
    {
        $provider=self::jobProvider($j); $p=$this->prov($provider); $now=$this->now();
        if (($j['phase'] ?? '')==='hide') { return $this->presenceHide($j,$o,$token,$provider); }
        $q=Db::name('avdb_import_map')->where('provider',$provider)->where('remote_id','>',(int)($o['_cursor'] ?? 0))->where('vod_id','>',0);
        if (!empty($o['recheck'])) { $q->where('state',0)->where('missed_at','>',0)->where('missed_at','<=',$now-self::RECHECK_AFTER); }
        else { $q->where('state','in',[0,1]); }
        if ((int)($o['parts'] ?? 1)>1) { $q->whereRaw('FLOOR(remote_id/'.self::PRESENCE_WINDOW.') % '.(int)$o['parts'].' = '.(int)$o['part']); }
        $rows=$q->field('remote_id,vod_id,state,seen_at,missed_at')->order('remote_id')->limit(self::PRESENCE_WINDOW)->select();
        if (!$rows) { return $this->presenceEnd($j,$o,$token,$provider); }
        $ids=array_map('intval',array_column($rows,'remote_id'));
        $present=array_fill_keys(array_map('intval',$p->present($ids)),true);
        $seenBefore=0; foreach ($rows as $r) { if ((int)$r['seen_at']>0) { $seenBefore++; } }
        // Breaker (sweeps only: a recheck window holds missing ids by definition).
        if (empty($o['recheck']) && !$present && $seenBefore*2>=count($rows)) {
            $o['sweep_state']='aborted'; $o['_notice']=ImportProviders::text('presence_empty','Presence check aborted: the provider answered an empty list for {n} videos that were present before. Nothing was hidden; the check runs again later.',['n'=>count($rows)]);
            $this->checkpoint($j['job_id'],$token,['state'=>'complete','phase'=>'complete','error'=>$o['_notice'],'options'=>$this->json($o)]);
            self::trace('sweep_aborted'); return true;
        }
        $hereIds=[]; $goneIds=[]; $restoreVods=[]; $restoreIds=[];
        foreach ($rows as $r) { $rid=(int)$r['remote_id']; if (isset($present[$rid])) { $hereIds[]=$rid; if ((int)$r['state']===1) { $restoreIds[]=$rid; $restoreVods[]=(int)$r['vod_id']; } } else { $goneIds[]=$rid; } }
        Db::startTrans(); try {
            $this->fence($j['job_id'],$token);
            if ($hereIds) { Db::name('avdb_import_map')->where('provider',$provider)->where('remote_id','in',$hereIds)->update(['seen_at'=>$now,'missed_at'=>0]); }
            if ($restoreIds) { Db::name('avdb_import_map')->where('provider',$provider)->where('remote_id','in',$restoreIds)->where('state',1)->update(['state'=>0]); Db::name('vod')->where('vod_id','in',$restoreVods)->update(['vod_status'=>1]);
                // fp-port-c (FINISH-PORT-REVIEW-b P2-1): visible again only together with their search-queue entries
                $this->ftQueueTx($restoreVods,$o); }
            if ($goneIds && empty($o['recheck'])) { Db::name('avdb_import_map')->where('provider',$provider)->where('remote_id','in',$goneIds)->where('missed_at',0)->update(['missed_at'=>$now]); }
            if ($goneIds && !empty($o['recheck'])) { $o['_still']=array_values(array_unique(array_merge((array)($o['_still'] ?? []),$goneIds))); }
            $o['_cursor']=max($ids); $o['_seen']=(int)($o['_seen'] ?? 0)+count($hereIds); $o['_missing']=(int)($o['_missing'] ?? 0)+count($goneIds); $o['_restored']=(int)($o['_restored'] ?? 0)+count($restoreIds); $o['_windows']=(int)($o['_windows'] ?? 0)+1;
            $abort=!empty($o['recheck']) && count((array)($o['_still'] ?? []))>self::HIDE_MAX;
            if ($abort) { $o['sweep_state']='aborted'; $o['_notice']=self::hideNotice(count($o['_still']),0); unset($o['_still']); }
            Db::name('avdb_import_job')->where(['job_id'=>$j['job_id'],'lock_token'=>$token])->update($abort ? ['state'=>'complete','phase'=>'complete','error'=>$o['_notice'],'options'=>$this->json($o)] : ['phase'=>'presence','options'=>$this->json($o)]);
            Db::commit();
        } catch (\Throwable $e) { Db::rollback(); throw $e; }
        if ($restoreVods) { self::contract('FilterVocab','touched',[$restoreVods]); self::contract('FtIndex','touch',[$restoreVods]); self::contract('ScaleCounts','markDirty',['presence']); }
        if (!empty($abort)) { self::trace('recheck_aborted'); return true; }
        return false;
    }
    private static function hideNotice($n,$total)
    {
        return ImportProviders::text('presence_breaker','The provider no longer lists {n} of {total} videos (more than 2 % or more than 5,000). Nothing was hidden: check the provider, then review them in "Removed by the provider".',['n'=>$n,'total'=>$total ?: '?']);
    }
    private function providerVideos($provider) { return (int)Db::name('avdb_import_map')->where('provider',$provider)->where('vod_id','>',0)->where('state',0)->count(); }
    private function presenceEnd($j,array $o,$token,$provider)
    {
        $total=$this->providerVideos($provider);
        $limit=min(self::HIDE_MAX,(int)floor(self::HIDE_MAX_RATIO*max(1,$total)));
        if (empty($o['recheck'])) {
            $o['sweep_state']='ok';
            if ((int)($o['_missing'] ?? 0)>$limit) { $o['sweep_state']='aborted'; $o['_notice']=self::hideNotice((int)$o['_missing'],$total); }
            $this->checkpoint($j['job_id'],$token,['state'=>'complete','phase'=>'complete','error'=>(string)($o['_notice'] ?? ''),'options'=>$this->json($o)]);
            return true;
        }
        $still=(array)($o['_still'] ?? []);
        if (count($still)>$limit) {
            $o['sweep_state']='aborted'; $o['_notice']=self::hideNotice(count($still),$total); unset($o['_still']);
            $this->checkpoint($j['job_id'],$token,['state'=>'complete','phase'=>'complete','error'=>$o['_notice'],'options'=>$this->json($o)]);
            self::trace('recheck_aborted'); return true;
        }
        $this->checkpoint($j['job_id'],$token,['phase'=>'hide','options'=>$this->json($o)]);
        return false;
    }
    private function presenceHide($j,array $o,$token,$provider)
    {
        $still=array_values((array)($o['_still'] ?? []));
        $batch=array_slice($still,0,self::PRESENCE_WINDOW); $rest=array_slice($still,self::PRESENCE_WINDOW);
        $vods=[];
        Db::startTrans(); try {
            $this->fence($j['job_id'],$token);
            if ($batch) {
                $rows=Db::name('avdb_import_map')->where('provider',$provider)->where('remote_id','in',$batch)->where('state',0)->where('vod_id','>',0)->where('missed_at','>',0)->field('remote_id,vod_id')->lock(true)->select();
                $vods=array_values(array_unique(array_map('intval',array_column($rows,'vod_id'))));
                if ($rows) { Db::name('avdb_import_map')->where('provider',$provider)->where('remote_id','in',array_map('intval',array_column($rows,'remote_id')))->update(['state'=>1]); }
                if ($vods) { Db::name('vod')->where('vod_id','in',$vods)->update(['vod_status'=>0]); $this->ftQueueTx($vods,$o); } // fp-port-c: queued with the hide
                $o['_hidden']=(int)($o['_hidden'] ?? 0)+count($rows);
            }
            $o['_still']=$rest; $done=!$rest;
            if ($done) { $o['sweep_state']='ok'; unset($o['_still']); }
            Db::name('avdb_import_job')->where(['job_id'=>$j['job_id'],'lock_token'=>$token])->update($done ? ['state'=>'complete','phase'=>'complete','options'=>$this->json($o)] : ['options'=>$this->json($o)]);
            Db::commit();
        } catch (\Throwable $e) { Db::rollback(); throw $e; }
        if ($vods) { $this->afterHide($vods); }
        if (!empty($done)) { if (!$vods) { self::contract('Vod','bumpListRevision',[true]); } return true; }
        return false;
    }
    /** "N videos removed by the provider" (hidden by the presence sweep), newest first (E's GET avdbimport/takedowns). */
    public function takedowns($provider,$page = 1,$size = 50)
    {
        if (!ImportProviders::isKey($provider)) { throw new \RuntimeException(ImportProviders::text('provider_unknown','Unknown import provider.')); }
        $page=max(1,min(100000,(int)$page)); $size=max(1,min(100,(int)$size));
        $where=['m.provider'=>$provider,'m.state'=>1];
        $total=(int)Db::name('avdb_import_map')->alias('m')->where($where)->where('m.vod_id','>',0)->count();
        $rows=Db::name('avdb_import_map')->alias('m')->join('vod v','v.vod_id=m.vod_id','LEFT')->where($where)->where('m.vod_id','>',0)->field('m.vod_id,m.remote_id,m.missed_at,v.vod_name')->order('m.missed_at desc,m.remote_id desc')->limit(($page-1)*$size,$size)->select();
        $older=(int)Db::name('avdb_import_map')->where(['provider'=>$provider,'state'=>1])->where('vod_id','>',0)->where('missed_at','<=',$this->now()-30*86400)->count();
        return ['items'=>$rows,'total'=>$total,'older_30d'=>$older,'has_more'=>$page*$size<$total];
    }
    /** Permanently delete the videos the provider removed more than 30 days ago (admin decision; tombstones stay). */
    public function takedownDelete($owner,$provider,$scope = 'older_30d')
    {
        if ((int)$owner<1) { throw new \RuntimeException('Authentication required.'); }
        if (!ImportProviders::isKey($provider) || $scope!=='older_30d') { throw new \RuntimeException('Invalid takedown scope.'); }
        $ids=array_map('intval',Db::name('avdb_import_map')->where(['provider'=>$provider,'state'=>1])->where('vod_id','>',0)->where('missed_at','<=',$this->now()-30*86400)->limit(500)->column('vod_id'));
        if (!$ids) { return ['deleted'=>0]; }
        $r=\app\common\util\VodDeletion::deleteWhere(['vod_id'=>['in',$ids]]);
        return ['deleted'=>(int)($r['deleted'] ?? 0)];
    }

    // ------------------------------------------------------------------ scopes, estimate, provider panel (P steps 11-12)

    /** xvid source languages of titles with their share of the catalog (1,000 newest rows, XVID-IMPORTER 1.3). */
    public const SOURCE_LANGUAGES = ['en' => 0.613, 'pt' => 0.038, 'es' => 0.016, 'ja' => 0.007, 'ru' => 0.005, 'fr' => 0.005, 'hi' => 0.004, 'zh' => 0.003, 'de' => 0.003, 'th' => 0.002, 'vi' => 0.002, 'ar' => 0.002, 'tl' => 0.001];

    /** Owner (admin id) of job $id, null when it does not exist (tools/import.php runs the jobs of every admin). */
    public static function jobOwner($id)
    {
        if (!is_string($id) || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $id)) { return null; }
        try { $v = Db::name('avdb_import_job')->where('job_id', $id)->value('admin_id'); } catch (\Throwable $e) { return null; }
        return $v === null ? null : (int)$v;
    }

    /**
     * Jobs a command-line runner steps (tools/import.php --run): running jobs (any provider) started for the command
     * line, oldest first; $all adds the web jobs too. Jobs of the automatic schedule are stepped by --tick only.
     */
    public static function runnableJobs($all = false, $limit = 20)
    {
        try { $rows = Db::name('avdb_import_job')->where('state', 'in', self::RUN_STATES)->field('job_id,admin_id,options,retry_at,lock_until,provider')->order('created_at asc')->limit(200)->select(); } catch (\Throwable $e) { return []; }
        $out = [];
        foreach ($rows as $r) {
            $o = json_decode((string)$r['options'], true); $o = is_array($o) ? $o : [];
            if (!empty($o['_schedule_client_id'])) { continue; }
            if (!$all && ($o['runner'] ?? 'web') !== 'cli') { continue; }
            $out[] = ['job_id' => (string)$r['job_id'], 'admin_id' => (int)$r['admin_id'], 'retry_at' => (int)$r['retry_at'], 'lock_until' => (int)$r['lock_until'], 'provider' => (string)($r['provider'] ?? 'avdb'), 'type' => (string)($o['type'] ?? 'import'), 'runner' => (string)($o['runner'] ?? 'web')];
            if (count($out) >= $limit) { break; }
        }
        return $out;
    }

    /**
     * Engine options from a scope request: E's page (provider, scope, limit, type, runner, publish, images, skip_short,
     * skip_unknown, skip_sd, langs, preset, categories_off, confirm) or tools/import.php --start (the same names,
     * categories as a list). scope: recent_10000 | recent_50000 | recent_100000 | recent_1000000 | recent (+ limit) |
     * category (+ type or categories) | full | incremental (+ hours) | selected (+ ids). normalizeOptions() validates
     * the result again, so nothing here is trusted further than this mapping.
     */
    public static function scopeOptions(array $p)
    {
        $provider = $p['provider'] ?? '';
        if (!is_string($provider) || !ImportProviders::isKey($provider)) { throw new \RuntimeException(ImportProviders::text('provider_unknown', 'Unknown import provider.')); }
        $caps = ImportProviders::get($provider)->capabilities();
        $scope = $p['scope'] ?? '';
        if (!is_string($scope)) { $scope = ''; }
        $o = ['provider' => $provider, 'runner' => $p['runner'] ?? 'web', 'publish' => array_key_exists('publish', $p) ? (empty($p['publish']) ? 0 : 1) : 1, 'create_categories' => 1, 'download_images' => empty($p['images']) ? 0 : 1, 'existing' => 'smart'];
        if (isset($p['via']) && in_array($p['via'], ['snapshot', 'windows'], true)) { $o['via'] = $p['via']; }
        $positive = function ($v, $max) { return (is_int($v) || (is_string($v) && preg_match('/^[1-9][0-9]{0,9}$/D', $v))) && (int)$v >= 1 && (float)$v <= $max; };
        if (isset(ImportProviders::SCOPES[$scope])) {
            $o['mode'] = 'recent'; $o['max_items'] = ImportProviders::SCOPES[$scope];
        } elseif ($scope === 'recent') {
            $limit = $p['limit'] ?? null;
            if (!$positive($limit, 2147483647)) { throw new \RuntimeException('Item limit must be a positive whole number up to 2147483647 (the supported integer range).'); }
            $o['mode'] = 'recent'; $o['max_items'] = (int)$limit;
        } elseif ($scope === 'category') {
            $cats = $p['categories'] ?? ($p['type'] ?? []);
            if (is_string($cats) || is_int($cats)) { $cats = explode(',', (string)$cats); }
            $o['mode'] = 'category'; $o['categories'] = array_values(array_filter(array_map('trim', array_map('strval', (array)$cats)), 'strlen'));
            $limit = $p['limit'] ?? 0;
            $o['max_items'] = $positive($limit, 2147483647) ? (int)$limit : 0;
        } elseif ($scope === 'full') {
            if (($caps['stable_cursor'] ?? '') === 'id_windows') { $o['mode'] = 'windows'; $o['max_items'] = 0; }
            elseif (($caps['stable_cursor'] ?? '') === 'export') { $o['mode'] = 'all'; $o['max_items'] = 0; $o['filters'] = []; }
            else { throw new \RuntimeException('Unknown import scope.'); }
        } elseif ($scope === 'incremental') {
            $o['mode'] = 'incremental'; $o['hours'] = (int)($p['hours'] ?? 24); $o['max_items'] = 0;
        } elseif ($scope === 'selected') {
            $ids = $p['ids'] ?? [];
            if (is_string($ids)) { $ids = explode(',', $ids); }
            $o['mode'] = 'selected'; $o['ids'] = array_values(array_filter(array_map('trim', array_map('strval', (array)$ids)), 'strlen'));
            if (!empty($p['override_tombstones'])) { $o['override_tombstones'] = 1; }
        } else {
            throw new \RuntimeException('Unknown import scope.');
        }
        if (!empty($caps['category_parent'])) {
            $xf = [];
            foreach (['skip_short', 'skip_unknown', 'skip_sd'] as $k) { if (array_key_exists($k, $p)) { $xf[$k] = empty($p[$k]) || $p[$k] === '0' ? 0 : 1; } }
            if (array_key_exists('langs', $p)) {
                $langs = $p['langs']; if (is_string($langs)) { $langs = $langs === '' ? [] : explode(',', $langs); }
                $langs = array_values(array_unique(array_filter(array_map('strval', (array)$langs), 'strlen')));
                // every offered language ticked = no language filter (rows with an undetermined language stay)
                $offered = array_keys(self::SOURCE_LANGUAGES);
                $xf['langs'] = ($langs && array_diff($offered, $langs)) ? $langs : [];
            }
            $o['xfilters'] = $xf;
            if (isset($p['preset'])) { $o['preset'] = $p['preset']; }
            if (array_key_exists('categories_off', $p)) {
                $off = $p['categories_off']; if (is_string($off)) { $off = $off === '' ? [] : explode(',', $off); }
                $o['skip_types'] = array_values(array_filter(array_map('trim', array_map('strval', (array)$off)), 'strlen'));
                // the page sends the complete list of unticked categories: Gay / Trans follow the ticks, not D5
                $o['include_gay_trans'] = 1;
            }
        } elseif (!empty($caps['translations']) && array_key_exists('langs', $p)) {
            $langs = $p['langs']; if (is_string($langs)) { $langs = $langs === '' ? [] : explode(',', $langs); }
            $o['locales'] = array_values(array_filter(array_map('strval', (array)$langs), 'strlen'));
        }
        foreach (['placement', 'default_player'] as $k) { if (isset($p[$k])) { $o[$k] = $p[$k]; } }
        // P-O1: opt-in drop of the filter FULLTEXT keys during a large bulk job (measured: not much faster, so off by default)
        if (!empty($p['drop_indexes']) && $p['drop_indexes'] !== '0') { $o['drop_indexes'] = 1; }
        return $o;
    }

    /**
     * C7 estimate for a scope request, with the server's own measurements (database size and limit, binlog, free disk,
     * CLI heartbeat, the site's import rate, cached provider totals, whether a background step can run). Never calls the
     * provider except the daily xvidexport manifest check. $web is kept for callers; no scope is capped for the web.
     */
    public function estimateFor(array $p, $web = true)
    {
        $provider = $p['provider'] ?? 'avdb';
        if (!is_string($provider) || !ImportProviders::isKey($provider)) { throw new \RuntimeException(ImportProviders::text('provider_unknown', 'Unknown import provider.')); }
        $cli = ProviderPacer::cliSeenAt(); $hasCli = $cli > 0 && time() - $cli <= 86400;
        // A1: the whole catalog is the default scope (the storage target decides how far it goes)
        $scope = isset($p['scope']) && is_string($p['scope']) && $p['scope'] !== '' ? $p['scope'] : 'full';
        $st = StorageGuard::status();
        $state = ProviderPacer::rows()[$provider] ?? [];
        $totals = self::providerTotals($provider);
        $mapped = self::mappedCount($provider);
        $in = ['provider' => $provider, 'scope' => $scope, 'now' => time(),
            'db_mb_now' => (int)($st['db_mb'] ?? 0), 'db_limit_mb' => StorageGuard::limitMb(), 'db_limit_asked' => StorageGuard::limitSetting() !== '',
            'binlog_on' => !empty($st['binlog']['on']), 'binlog_expire_days' => (int)($st['binlog']['expire_days'] ?? 0),
            'binlog_format' => $st['binlog']['format'] ?? null, 'binlog_annotate' => $st['binlog']['annotate'] ?? null,
            'cli_seen_at' => $cli, 'rate_items_s' => (float)($state['rate_items_s'] ?? 0), 'items_done' => $mapped,
            'images' => !empty($p['images']), 'web' => (bool)$web, 'slim' => !empty($p['slim']), 'local_videos' => self::videoCount(),
            'background' => function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request')];
        if (isset($st['ft_mb']) && $st['ft_mb'] !== null) { $in['search_mb_now'] = (int)$st['ft_mb']; }
        if ($st['free_disk_mb'] !== null) { $in['free_disk_mb'] = (int)$st['free_disk_mb']; }
        if ($st['tmp_free_mb'] !== null) { $in['tmp_free_mb'] = (int)$st['tmp_free_mb']; }
        if (!empty($totals[0])) { $in['remote_total'] = (int)$totals[0]; }
        if (array_key_exists('skip_short', $p) || array_key_exists('skip_unknown', $p)) { $in['filters'] = !empty($p['skip_short']) || !empty($p['skip_unknown']); }
        if ($scope === 'recent') { $in['videos'] = max(1, (int)($p['limit'] ?? 0)); }
        if ($scope === 'category') {
            $cats = $p['categories'] ?? ($p['type'] ?? []);
            if (is_string($cats) || is_int($cats)) { $cats = explode(',', (string)$cats); }
            // cached totals; a category never counted is measured at its first page (videos_unknown)
            $n = 0; $unknown = 0; foreach ((array)$cats as $c) { $c = (int)$c; if ($c > 0) { if (!empty($totals[$c])) { $n += (int)$totals[$c]; } else { $unknown++; } } }
            $in['videos'] = $n; $in['videos_unknown'] = $unknown;
        }
        if ($scope === 'selected') { $ids = $p['ids'] ?? []; $in['videos'] = count(is_string($ids) ? explode(',', $ids) : (array)$ids); }
        if ($provider === self::PROVIDER) {
            $langs = $p['langs'] ?? null; if (is_string($langs)) { $langs = explode(',', $langs); }
            $in['translations'] = is_array($langs) ? count(array_diff($langs, ['en', ''])) > 0 : true;
        }
        if ($scope === 'incremental') { $in['scope'] = 'recent'; $in['videos'] = 1000; }
        if ($scope === 'full' && $provider !== self::PROVIDER && (string)($p['via'] ?? '') !== 'windows') {
            // daily detection (contract section 4): at most one manifest request per day, through the provider's pacer
            try { $xp = $this->prov($provider); $m = method_exists($xp, 'manifest') ? $xp->manifest() : null; } catch (\Throwable $e) { $m = null; }
            if ($m) { $in['snapshot'] = ['shards' => count($m['shards']), 'bytes' => (int)$m['download_bytes'], 'feed_pages' => 1]; $in['remote_total'] = (int)$m['catalog_total']; }
        }
        if (array_key_exists('skip_short', $p) || array_key_exists('skip_unknown', $p)) { /* set above */ } elseif ($scope === 'full') { $in['filters'] = false; }
        return ImportProviders::estimate($in);
    }

    /**
     * Start a scope job (HO-E-4, XVID-NOLIMIT-BRIEF A1-A3): no size rule for the web runner (a web job continues by itself
     * through tickWeb), no typed confirmation and no refusal for size (the job stops cleanly at the storage target). A
     * command-line job still needs a CLI heartbeat in the last 24 hours (unless it comes from tools/import.php itself:
     * a CLI job without a runner would never move, L6), and `confirm` = 1 when the estimate asks for it (confirm_needed:
     * more than 1,000,000 videos or more than half of the space left; a misclick guard, not a limit).
     */
    public function startScope($owner, array $p, $fromCli = false)
    {
        $o = self::scopeOptions($p);
        $est = $this->estimateFor($p, $o['runner'] === 'web');
        if ($o['runner'] === 'cli' && !$fromCli) {
            $cli = ProviderPacer::cliSeenAt();
            if ($cli <= 0 || time() - $cli > 86400) { throw new \RuntimeException(ImportProviders::text('cli_none', 'No command-line run was seen in the last 24 hours. Add this cron line first: {cron}', ['cron' => $est['cron_line']])); }
        }
        $confirm = $p['confirm'] ?? null;
        if (!empty($est['confirm_needed']) && !($confirm === 1 || $confirm === true || $confirm === '1' || (is_string($confirm) && preg_match('/^IMPORT [0-9]{1,10} VIDEOS$/D', $confirm)))) {
            throw new \RuntimeException(ImportProviders::text('confirm_phrase', 'Tick the confirmation box to start this import.'));
        }
        $status = $this->start($owner, $o);
        $status['estimate'] = $est;
        return $status;
    }

    /** Cached remote totals of a provider: [0 => catalog total, remote_type_id => category total] (avdb_provider_type). */
    public static function providerTotals($provider)
    {
        $out = [];
        try { foreach (Db::name('avdb_provider_type')->where('provider', (string)$provider)->field('remote_type_id,remote_total')->select() as $r) { if ((int)$r['remote_total'] > 0) { $out[(int)$r['remote_type_id']] = (int)$r['remote_total']; } } } catch (\Throwable $e) { /* schema not yet there */ }
        return $out;
    }

    /** Store a remote total (row 0 = the whole catalog; local_type_id 0, remote_name '' for row 0, so categorySetup never reads it). */
    public static function storeTotal($provider, $remoteTypeId, $total)
    {
        if (!ImportProviders::isKey($provider) || (int)$remoteTypeId < 0 || (int)$total < 0) { return; }
        try {
            $where = ['provider' => $provider, 'remote_type_id' => (int)$remoteTypeId];
            if (Db::name('avdb_provider_type')->where($where)->find()) { Db::name('avdb_provider_type')->where($where)->update(['remote_total' => (int)$total, 'total_at' => time(), 'updated_at' => time()]); }
            else { Db::name('avdb_provider_type')->insert($where + ['local_type_id' => 0, 'remote_name' => '', 'import' => 0, 'remote_total' => (int)$total, 'total_at' => time(), 'updated_at' => time()]); }
        } catch (\Throwable $e) { error_log('[AVDB] provider total: ' . get_class($e)); }
    }

    /** Local videos of a provider (map rows with a video), cached 60 s (the map holds millions of rows at scale). */
    public static function mappedCount($provider)
    {
        $key = 'avdb_mapped_' . preg_replace('/[^a-z0-9_]/', '', (string)$provider);
        try { $v = \think\Cache::get($key); if (is_numeric($v)) { return (int)$v; } } catch (\Throwable $e) { /* no cache */ }
        try { $n = (int)Db::name('avdb_import_map')->where('provider', (string)$provider)->where('vod_id', '>', 0)->count(); } catch (\Throwable $e) { return 0; }
        try { \think\Cache::set($key, $n, 60); } catch (\Throwable $e) { /* no cache */ }
        return $n;
    }

    /** Videos on the site: ScaleCounts (C4) when it has a count, else the table statistics (never a COUNT over the table). */
    private static function videoCount()
    {
        $n = self::contract('ScaleCounts', 'all');
        if (is_int($n)) { return $n; }
        try { $t = Db::query('SELECT table_rows AS n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', [(string)config('database.prefix') . 'vod']); return (int)($t[0]['n'] ?? 0); } catch (\Throwable $e) { return 0; }
    }

    /**
     * C7 `providers` rows with E's additive keys (HO-E-3): videos, state {next_request_at, interval_ms, failures,
     * retry_at, last_status, paused_until, message}, cli_seen_at, undo_rate_s, takedowns {hidden, older_30d,
     * sweep_state, sweep_at, sweep_msg}.
     */
    public function providersInfo()
    {
        $rows = ProviderPacer::rows();
        $list = ImportProviders::describe($rows);
        $cli = ProviderPacer::cliSeenAt();
        $undo = self::undoRate();
        foreach ($list as &$p) {
            $key = $p['key']; $s = $rows[$key] ?? [];
            $p['videos'] = self::mappedCount($key);
            $p['state'] = ['next_request_at' => (int)ceil((float)($s['next_request_at'] ?? 0)), 'interval_ms' => (int)($s['interval_ms'] ?? 0), 'failures' => (int)($s['failures'] ?? 0), 'retry_at' => (int)($s['retry_at'] ?? 0), 'last_status' => (int)($s['last_status'] ?? 0), 'paused_until' => (int)($s['retry_at'] ?? 0) > time() ? (int)$s['retry_at'] : 0, 'message' => (string)($s['last_error'] ?? '')];
            $p['cli_seen_at'] = $cli;
            $p['undo_rate_s'] = $undo;
            $p['takedowns'] = $this->takedownSummary($key);
        }
        unset($p);
        return $list;
    }

    private function takedownSummary($provider)
    {
        $out = ['hidden' => 0, 'older_30d' => 0, 'sweep_state' => '', 'sweep_at' => 0, 'sweep_msg' => ''];
        try {
            $out['hidden'] = (int)Db::name('avdb_import_map')->where(['provider' => $provider, 'state' => 1])->where('vod_id', '>', 0)->count();
            $out['older_30d'] = $out['hidden'] ? (int)Db::name('avdb_import_map')->where(['provider' => $provider, 'state' => 1])->where('vod_id', '>', 0)->where('missed_at', '<=', $this->now() - 30 * 86400)->count() : 0;
            $job = Db::name('avdb_import_job')->where('provider', $provider)->where('options', 'like', '%"mode":"presence"%')->where('state', 'in', ['complete', 'paused', 'x_paused', 'cancelled'])->order('updated_at desc')->field('options,updated_at')->find();
            if ($job) { $o = $this->decode($job['options']); $out['sweep_state'] = (string)($o['sweep_state'] ?? ''); $out['sweep_at'] = (int)$job['updated_at']; $out['sweep_msg'] = (string)($o['_notice'] ?? ''); }
        } catch (\Throwable $e) { /* schema not yet there */ }
        return $out;
    }

    /**
     * `capabilities?provider=<key>` (HO-E-4). AVDB: the 1.1.0 answer. Other providers: their categories (cached weekly,
     * else one list call through the pacer; a busy clock answers from the cache), remote totals, the presets
     * Grouped / Mirror with default ticks (D5), and the source languages with their share of the catalog.
     */
    public function providerCapabilities($provider)
    {
        if (!is_string($provider) || !ImportProviders::isKey($provider)) { throw new \RuntimeException(ImportProviders::text('provider_unknown', 'Unknown import provider.')); }
        if ($provider === self::PROVIDER) { return $this->capabilities() + ['provider' => $provider, 'capabilities' => $this->caps($provider)]; }
        $base = $this->capabilities(false);
        $p = $this->prov($provider, 'interactive');
        $names = []; $error = '';
        try {
            $names = $this->providerClassNames($p);
            if (property_exists($p, 'lastTotal') && $p->lastTotal !== null) { self::storeTotal($provider, 0, (int)$p->lastTotal); }
        } catch (ProviderWaitException $e) {
            $error = ImportProviders::text('provider_wait', 'The provider is busy; try again in {s} seconds.', ['s' => max(1, (int)$e->retryAt - time())]);
            try { foreach (Db::name('avdb_provider_type')->where('provider', $provider)->where('remote_name', '<>', '')->field('remote_type_id,remote_name')->select() as $r) { $names[(int)$r['remote_type_id']] = (string)$r['remote_name']; } } catch (\Throwable $x) { /* none */ }
        } catch (\Throwable $e) { $error = mb_substr($e->getMessage(), 0, 255, 'UTF-8'); }
        $totals = self::providerTotals($provider);
        $cats = [];
        foreach ($names as $id => $name) { if (!in_array(mb_strtolower($name, 'UTF-8'), self::NEVER_CREATE, true)) { $cats[] = ['type_id' => (int)$id, 'type_name' => $name, 'remote_total' => (int)($totals[(int)$id] ?? 0)]; } }
        $langs = [];
        foreach (self::SOURCE_LANGUAGES as $code => $share) { $langs[] = ['code' => $code, 'label' => (string)(XvidClient::LANG_NAMES[$code] ?? $code), 'share' => $share]; }
        return ['provider' => $provider, 'provider_error' => $error, 'ready' => $base['ready'], 'local_categories' => $base['local_categories'], 'players' => $base['players'], 'languages' => [], 'provider_categories' => $cats, 'default_player' => $base['default_player'], 'capabilities' => $this->caps($provider), 'catalog_total' => (int)($totals[0] ?? 0), 'source_languages' => $langs, 'presets' => self::presets($provider, $names)];
    }

    /**
     * Presets for the scope form: grouped / mirror rows {key, label, remote_ids, default_on, default_on_small}. O2
     * (XVID-NOLIMIT-BRIEF): every row is ticked for the whole catalog, the page's default scope (one click unticks a
     * row); default_on_small keeps D5 (Gay / Shemale / Bi Sexual unticked) for the smaller scopes.
     */
    public static function presets($provider, array $names)
    {
        $byName = []; foreach ($names as $id => $n) { $byName[mb_strtolower($n, 'UTF-8')] = (int)$id; }
        $off = array_fill_keys(self::DEFAULT_OFF, true); $never = array_fill_keys(self::NEVER_CREATE, true);
        $grouped = []; $used = [];
        foreach (self::GROUPED as $g) {
            $ids = [];
            foreach ($g[1] as $member) { $k = mb_strtolower($member, 'UTF-8'); if (isset($byName[$k])) { $ids[] = $byName[$k]; $used[$k] = true; } }
            $grouped[] = ['name' => $g[0], 'members' => $g[1], 'ids' => $ids];
        }
        $rows = [];
        foreach ($grouped as $g) {
            $ids = $g['ids'];
            // "Other": categories of no group, and (O1) the rows of Xvidapi / Unknown, which go to the child xv-other
            if (!$g['members']) { foreach ($byName as $k => $id) { if (!isset($used[$k])) { $ids[] = $id; } } }
            if (!$ids) { continue; }
            $allOff = true; foreach ($g['members'] as $m) { if (!isset($off[mb_strtolower($m, 'UTF-8')])) { $allOff = false; } }
            sort($ids);
            $rows[] = ['key' => $provider . '-' . self::categorySlug($g['name'], 0), 'label' => $g['name'], 'remote_ids' => $ids, 'default_on' => true, 'default_on_small' => !($g['members'] && $allOff)];
        }
        $mirror = [];
        foreach ($names as $id => $n) {
            $k = mb_strtolower($n, 'UTF-8'); if (isset($never[$k])) { continue; }
            $mirror[] = ['key' => $provider . '-' . self::categorySlug($n, (int)$id), 'label' => $n, 'remote_ids' => [(int)$id], 'default_on' => true, 'default_on_small' => !isset($off[$k])];
        }
        return ['grouped' => $rows, 'mirror' => $mirror];
    }

    // ------------------------------------------------------------------ undo / remove all (D-I13)

    /** Videos an undo of $id would delete (and the time shown before the start). */
    public function undoEstimate($owner,$id)
    {
        $j=$this->job($owner,$id); $o=$this->decode($j['options']);
        $n=!empty($o['bulk']) ? $this->rangeCount(self::jobProvider($j),(int)($o['_vod_min'] ?? 0),(int)($o['_vod_max'] ?? 0) ?: PHP_INT_MAX) : (int)Db::name('avdb_import_item')->where(['job_id'=>$id,'state'=>'created'])->count();
        return ['videos'=>$n,'seconds'=>(int)ceil($n/max(1,self::undoRate()))];
    }
    /** Measured deletion rate (videos per second) of the last undo, 50/s until one ran. */
    public static function undoRate()
    {
        try { $row=Db::name('avdb_import_job')->where('options','like','%"type":"undo"%')->where('state','complete')->order('updated_at desc')->field('options')->find(); $o=$row ? json_decode((string)$row['options'],true) : null; if (is_array($o) && !empty($o['_undo_rate'])) { return (float)$o['_undo_rate']; } } catch (\Throwable $e) { /* none */ }
        return 50.0;
    }
    private function rangeCount($provider,$min,$max) { return (int)Db::name('avdb_import_map')->where('provider',$provider)->where('vod_id','>=',max(1,$min))->where('vod_id','<=',$max)->count(); }
    /**
     * Undo job $id: a resumable job (type undo, 500 videos per step) that deletes the videos the job created and their
     * map rows (no tombstones, so a smaller re-import works). Bulk jobs (created items are not journaled) use the
     * provider's map rows inside the job's vod id range.
     */
    public function undo($owner,$id)
    {
        $j=$this->job($owner,$id); $o=$this->decode($j['options']);
        if (in_array($j['state'],self::RUN_STATES,true) || (int)$j['lock_until']>time()) { throw new \RuntimeException(ImportProviders::text('undo_busy','Pause or finish this import before undoing it.')); }
        if (($o['type'] ?? 'import')!=='import') { throw new \RuntimeException(ImportProviders::text('undo_only_imports','Only imports can be undone.')); }
        $provider=self::jobProvider($j);
        if (!empty($o['bulk']) && empty($o['_bulk_closed'])) { self::closeBulkWindow($id,'undo',PHP_SAPI!=='cli'); $o=$this->decode($this->job($owner,$id)['options']); }
        if (!empty($o['bulk'])) { self::ftKeysRelease($id); }
        $source=!empty($o['bulk']) ? ['by'=>'range','min'=>(int)($o['_vod_min'] ?? 0),'max'=>(int)($o['_vod_max'] ?? 0) ?: (int)Db::name('vod')->max('vod_id')] : ['by'=>'items','job'=>$id];
        if ($source['by']==='range' && $source['min']<1) { throw new \RuntimeException(ImportProviders::text('undo_no_range','This import has no recorded video range.')); }
        $count=$source['by']==='range' ? $this->rangeCount($provider,$source['min'],$source['max']) : (int)Db::name('avdb_import_item')->where(['job_id'=>$id,'state'=>'created'])->count();
        return $this->startUndo($owner,$provider,$source+['target'=>$id],$count);
    }
    /** "Remove all videos from <provider>": confirm = 'REMOVE <N> VIDEOS' (N = the provider's videos now). Also disables its schedule plan. */
    public function removeAll($owner,$provider,$confirm)
    {
        if (!ImportProviders::isKey($provider)) { throw new \RuntimeException(ImportProviders::text('provider_unknown','Unknown import provider.')); }
        $this->ensureSchema();
        $n=(int)Db::name('avdb_import_map')->where('provider',$provider)->where('vod_id','>',0)->count();
        if (!is_string($confirm) || $confirm!=='REMOVE '.$n.' VIDEOS') { throw new \RuntimeException(ImportProviders::text('remove_confirm','Type REMOVE {n} VIDEOS to confirm.',['n'=>$n])); }
        AvdbSchedule::disablePlan($provider,ImportProviders::text('plan_removed','All videos of this provider were removed.'));
        foreach (Db::name('avdb_import_job')->where('provider',$provider)->where('options','like','%"bulk":1%')->where('state','not in',self::RUN_STATES)->column('job_id') as $bulkJob) { self::closeBulkWindow($bulkJob,'remove_all',PHP_SAPI!=='cli'); self::ftKeysRelease($bulkJob); }
        return $this->startUndo($owner,$provider,['by'=>'provider','min'=>1,'max'=>2147483647,'target'=>''],$n);
    }
    private function startUndo($owner,$provider,array $source,$count)
    {
        if ((int)$owner<1) { throw new \RuntimeException('Authentication required.'); }
        \app\common\model\VodCleanup::assertWritesAllowed();
        $this->ensureSchema();
        if (Db::name('avdb_import_job')->where('state','in',self::RUN_STATES)->where('options','like','%"type":"undo"%')->find()) { throw new \RuntimeException(ImportProviders::text('undo_running','Another undo is running. Wait for it to finish.')); }
        $docs=self::contract('ScaleCounts','all'); if (!is_int($docs)) { $t=Db::query('SELECT table_rows AS n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',[(string)config('database.prefix').'vod']); $docs=(int)($t[0]['n'] ?? 0); }
        $rebuild=$docs>0 && $count>self::UNDO_REBUILD_RATIO*$docs;
        $o=['type'=>'undo','mode'=>'undo','provider'=>$provider,'source'=>$source,'max_items'=>0,'locales'=>['en'],'_to_delete'=>$count,'_deleted'=>0,'_cursor'=>0,'ft'=>$rebuild?'rebuild':'forget','runner'=>PHP_SAPI==='cli'?'cli':'web'];
        if ($provider!==self::PROVIDER) { $o['_vod_cleanup_sealed']='provider:'.$provider; }
        $id=bin2hex(random_bytes(16));
        Db::name('avdb_import_job')->insert(['job_id'=>$id,'admin_id'=>(int)$owner,'options'=>$this->json($o),'state'=>self::stateFor('running',$provider),'phase'=>'undo','page'=>1,'cursor'=>0,'discovery_done'=>1,'lock_token'=>'','lock_until'=>0,'error'=>'','retry_at'=>0,'created_at'=>time(),'updated_at'=>time(),'provider'=>$provider]);
        if ($rebuild) { self::contract('FtIndex','markRebuild',['undo']); }
        return $this->status($owner,$id);
    }
    private function undoStep($j,array $o,$token,$limit=self::UNDO_STEP)
    {
        $provider=self::jobProvider($j); $src=(array)$o['source']; $cursor=(int)($o['_cursor'] ?? 0); $t0=microtime(true);
        $limit=max(1,min(self::UNDO_STEP,(int)$limit)); // round 4 (flood-r4 N17): as many videos as the step admitted
        if ($src['by']==='items') {
            $rows=Db::name('avdb_import_item')->where(['job_id'=>(string)$src['job'],'state'=>'created'])->where('item_id','>',$cursor)->where('vod_id','>',0)->order('item_id')->limit($limit)->field('item_id,vod_id')->select();
            $ids=array_values(array_unique(array_map('intval',array_column($rows,'vod_id')))); $next=$rows ? (int)max(array_column($rows,'item_id')) : $cursor;
        } else {
            $ids=array_map('intval',Db::name('avdb_import_map')->where('provider',$provider)->where('vod_id','>',max($cursor,(int)$src['min']-1))->where('vod_id','<=',(int)$src['max'])->order('vod_id')->limit($limit)->column('vod_id'));
            $ids=array_values(array_unique($ids)); $next=$ids ? max($ids) : $cursor;
        }
        if (!$ids) {
            if (!empty($o['_min_deleted'])) { self::contract('EdgeCache','purgeRange',[(int)$o['_min_deleted'],(int)$o['_max_deleted']]); }
            self::contract('Vod','bumpListRevision',[true]); self::contract('ScaleCounts','markDirty',['undo']); self::contract('ScaleTier','refresh');
            try { (new Vod())->clearListCache(); } catch (\Throwable $e) { error_log('[AVDB] list cache: '.get_class($e)); }
            $o['_undo_rate']=round((int)$o['_deleted']/max(0.001,(float)($o['_undo_s'] ?? 0.001)),1);
            $this->checkpoint($j['job_id'],$token,['state'=>'complete','phase'=>'complete','options'=>$this->json($o)]);
            if (!empty($src['target'])) { try { $tj=Db::name('avdb_import_job')->where('job_id',(string)$src['target'])->find(); if ($tj) { $to=$this->decode($tj['options']); $to['_undone_by']=$j['job_id']; $to['_undone_at']=time(); Db::name('avdb_import_job')->where('job_id',$tj['job_id'])->update(['options'=>$this->json($to),'error'=>ImportProviders::text('undone','Undone: {n} videos removed.',['n'=>(int)$o['_deleted']])]); } } catch (\Throwable $e) { /* informational */ } }
            return true;
        }
        // Videos of the batch: deleted through VodDeletion without tombstones; map rows of videos that no longer exist go too.
        $existing=array_map('intval',Db::name('vod')->where('vod_id','in',$ids)->column('vod_id'));
        $deleted=0;
        if ($existing) { $r=\app\common\util\VodDeletion::deleteWhere(['vod_id'=>['in',$existing]],['tombstones'=>false,'ft'=>$o['ft']==='rebuild'?'skip':'forget','purge'=>false,'own_job'=>(string)$j['job_id']]); $deleted=(int)($r['deleted'] ?? 0); }
        $gone=array_diff($ids,$existing); if ($gone) { Db::name('avdb_import_map')->where('provider',$provider)->where('vod_id','in',array_values($gone))->delete(); }
        $o['_cursor']=$next; $o['_deleted']=(int)$o['_deleted']+$deleted; $o['_undo_s']=round((float)($o['_undo_s'] ?? 0)+(microtime(true)-$t0),3);
        if ($existing) { $o['_min_deleted']=min((int)($o['_min_deleted'] ?? PHP_INT_MAX),min($existing)); $o['_max_deleted']=max((int)($o['_max_deleted'] ?? 0),max($existing)); }
        $this->checkpoint($j['job_id'],$token,['options'=>$this->json($o)]);
        return false;
    }
    /**
     * Compact journal fingerprint of a vod row ($list=false) or of a vod's translation rows ($list=true): sha1 of the
     * rows with sorted keys, every value as a string (null as ''), translation rows sorted by locale. Independent of
     * the driver's int / string fetch types (PHP 7.4 and 8.x), so a rollback compares it with the current state.
     */
    public static function stateHash($rows,$list=false)
    {
        $canonical=function($row) { $out=[]; foreach ((array)$row as $key=>$value) { $out[(string)$key]=$value===null ? '' : (is_scalar($value) ? (string)$value : serialize($value)); } ksort($out,SORT_STRING); return $out; };
        if (!$list) { return $rows===null ? '' : sha1(serialize($canonical($rows))); }
        $out=[]; foreach ((array)$rows as $row) { $out[]=$canonical($row); }
        usort($out,function($a,$b){ return strcmp(($a['locale'] ?? '').'|'.serialize($a),($b['locale'] ?? '').'|'.serialize($b)); });
        return sha1(serialize($out));
    }
    /**
     * Ids of the finished jobs whose items retention should trim: every finished job after the newest KEEP_FULL_RUNS
     * (newest first by created_at, job_id), except jobs already trimmed and not touched since (options._journal_trimmed_at).
     * Pure: $jobs are rows with job_id, state, created_at, updated_at, options. Oldest first.
     */
    public static function trimCandidates(array $jobs)
    {
        $finished=array_values(array_filter($jobs,function($job){ return in_array((string)$job['state'],self::FINISHED_JOB_STATES,true); }));
        usort($finished,function($a,$b){ return [(int)$b['created_at'],(string)$b['job_id']] <=> [(int)$a['created_at'],(string)$a['job_id']]; });
        $out=[];
        foreach (array_slice($finished,self::KEEP_FULL_RUNS) as $job) {
            $o=json_decode((string)$job['options'],true); $marker=is_array($o) ? (int)($o['_journal_trimmed_at'] ?? 0) : 0;
            if (!$marker || (int)$job['updated_at']>=$marker) { $out[]=(string)$job['job_id']; }
        }
        return array_reverse($out);
    }
    /** Ids of the finished jobs after the newest KEEP_HISTORY_RUNS (same order rule as trimCandidates), oldest first. Pure. */
    public static function historyCandidates(array $jobs)
    {
        $finished=array_values(array_filter($jobs,function($job){ return in_array((string)$job['state'],self::FINISHED_JOB_STATES,true); }));
        usort($finished,function($a,$b){ return [(int)$b['created_at'],(string)$b['job_id']] <=> [(int)$a['created_at'],(string)$a['job_id']]; });
        return array_reverse(array_map(function($job){ return (string)$job['job_id']; },array_slice($finished,self::KEEP_HISTORY_RUNS)));
    }
    /** trimHistory() for the importer's own call sites: never breaks an import, only logs. */
    private function trimHistoryQuietly()
    {
        try { $this->trimHistory(); } catch (\Throwable $e) { error_log('[AVDB] Journal retention: '.get_class($e).': '.$e->getMessage()); }
    }
    /**
     * Journal retention, run by the importer at the end of every job (complete, cancelled, rolled back) and at every
     * job start: clears payload, translations, before_image and after_image of the created / updated / skipped /
     * rolled-back items of the jobs that fell out of the newest KEEP_FULL_RUNS finished jobs. Pending and failed items
     * (retry), running / paused jobs and the newest KEEP_FULL_RUNS finished jobs are never touched (re-checked in every
     * statement). Jobs after the newest KEEP_HISTORY_RUNS finished ones are removed with all their items (their counts
     * are recorded in options._journal_counts first); a job whose step lease is held is left for a later call. Each
     * history job is visited once and then gone, so the cost stays bounded. Short autocommit statements of $batch rows;
     * stops after $seconds and continues at the next call. One worker at a time (named lock; a busy lock returns at
     * once). Returns ['jobs','rows','complete'] (+ 'deleted', 'jobs_removed' when history rows went).
     */
    public function trimHistory($seconds = self::TRIM_SECONDS,$batch = 500)
    {
        $result=['jobs'=>0,'rows'=>0,'complete'=>true];
        if (!$this->ready()) { return $result; }
        $prefix=(string)config('database.prefix'); if (!preg_match('/^[A-Za-z0-9_]*$/D',$prefix)) { throw new \RuntimeException('Invalid database prefix.'); }
        $lock='avdb_trim_'.substr(hash('sha256',(string)config('database.database').'|'.$prefix),0,40);
        $got=Db::query('SELECT GET_LOCK(:lock_name,0) AS acquired',['lock_name'=>$lock],true);
        if ((int)($got[0]['acquired'] ?? 0)!==1) { $result['complete']=false; return $result; }
        try {
            $deadline=microtime(true)+max(0.2,(float)$seconds); $batch=max(1,min(2000,(int)$batch));
            $finished="'".implode("','",self::FINISHED_JOB_STATES)."'";
            $jobs=Db::name('avdb_import_job')->where('state','in',self::FINISHED_JOB_STATES)->field('job_id,state,created_at,updated_at,options')->select();
            $history=self::historyCandidates($jobs);
            foreach (array_diff(self::trimCandidates($jobs),$history) as $jobId) {
                foreach (self::TRIM_ITEM_STATES as $state) {
                    $cursor=0;
                    do {
                        if (microtime(true)>=$deadline) { $result['complete']=false; return $result; }
                        $ids=array_map('intval',Db::name('avdb_import_item')->where(['job_id'=>$jobId,'state'=>$state,'item_id'=>['gt',$cursor]])->order('item_id')->limit($batch)->column('item_id'));
                        if (!$ids) { break; }
                        $cursor=max($ids);
                        $result['rows']+=(int)Db::execute('UPDATE `'.$prefix.'avdb_import_item` i JOIN `'.$prefix.'avdb_import_job` j ON j.job_id=i.job_id'
                            ." SET i.payload='',i.translations='',i.before_image='',i.after_image=''"
                            .' WHERE i.item_id IN ('.implode(',',$ids).") AND i.state='".$state."' AND j.state IN (".$finished.')'
                            ." AND (i.payload<>'' OR i.translations<>'' OR i.before_image<>'' OR i.after_image<>'')");
                    } while (count($ids)===$batch);
                }
                // Remember the job is done; it is trimmed again only if it runs again (updated_at moves past the marker).
                Db::startTrans();
                try {
                    $job=Db::name('avdb_import_job')->where('job_id',$jobId)->lock(true)->find(); $o=$job ? json_decode((string)$job['options'],true) : null;
                    if ($job && is_array($o) && in_array($job['state'],self::FINISHED_JOB_STATES,true)) { $o['_journal_trimmed_at']=time()+1; Db::name('avdb_import_job')->where('job_id',$jobId)->update(['options'=>$this->json($o)]); }
                    Db::commit();
                } catch (\Throwable $e) { Db::rollback(); throw $e; }
                $result['jobs']++;
            }
            // History bound: beyond the newest KEEP_HISTORY_RUNS finished jobs the whole job goes (items of every state, then the row).
            foreach ($history as $jobId) {
                Db::startTrans();
                try {
                    // Record the per-state counts once, before any row goes (status() keeps showing them).
                    $job=Db::name('avdb_import_job')->where('job_id',$jobId)->lock(true)->find(); $o=$job ? json_decode((string)$job['options'],true) : null;
                    if (!$job || !in_array($job['state'],self::FINISHED_JOB_STATES,true)) { Db::commit(); continue; }
                    if (!is_array($o)) { $o=[]; }
                    if (!isset($o['_journal_counts'])) { $o['_journal_counts']=[]; foreach (Db::name('avdb_import_item')->where('job_id',$jobId)->field('state,count(*) n')->group('state')->select() as $c) { $o['_journal_counts'][$c['state']]=(int)$c['n']; } Db::name('avdb_import_job')->where('job_id',$jobId)->update(['options'=>$this->json($o)]); }
                    Db::commit();
                } catch (\Throwable $e) { Db::rollback(); throw $e; }
                // Every item state (failed / pending too): the job is out of the admin's reach, nothing can retry or resume it there.
                // Walked per state along the work index (job_id, state, item_id); the statement re-checks finished + no step lease.
                foreach (array_column(Db::query('SELECT DISTINCT state FROM `'.$prefix.'avdb_import_item` WHERE job_id=?',[$jobId]),'state') as $state) {
                    $cursor=0;
                    do {
                        if (microtime(true)>=$deadline) { $result['complete']=false; return $result; }
                        $ids=array_map('intval',Db::name('avdb_import_item')->where(['job_id'=>$jobId,'state'=>(string)$state,'item_id'=>['gt',$cursor]])->order('item_id')->limit($batch)->column('item_id'));
                        if (!$ids) { break; }
                        $cursor=max($ids);
                        $result['deleted']=($result['deleted'] ?? 0)+(int)Db::execute('DELETE i FROM `'.$prefix.'avdb_import_item` i JOIN `'.$prefix.'avdb_import_job` j ON j.job_id=i.job_id'
                            .' WHERE i.item_id IN ('.implode(',',$ids).') AND j.state IN ('.$finished.') AND j.lock_until<='.time());
                    } while (count($ids)===$batch);
                }
                // The job row goes once it holds no items (re-checked: still finished, no step lease).
                Db::startTrans();
                try {
                    $job=Db::name('avdb_import_job')->where('job_id',$jobId)->lock(true)->find();
                    if ($job && in_array($job['state'],self::FINISHED_JOB_STATES,true) && (int)$job['lock_until']<=time() && !Db::name('avdb_import_item')->where('job_id',$jobId)->field('item_id')->find()) { Db::name('avdb_import_job')->where(['job_id'=>$jobId,'state'=>$job['state']])->delete(); $result['jobs_removed']=($result['jobs_removed'] ?? 0)+1; }
                    Db::commit();
                } catch (\Throwable $e) { Db::rollback(); throw $e; }
            }
        } finally { Db::query('SELECT RELEASE_LOCK(:lock_name) AS released',['lock_name'=>$lock],true); }
        return $result;
    }
    public function rollback($owner,$id)
    {
        $status=\app\common\util\VodDeletion::withMediaLock(function () use ($owner,$id) { \app\common\model\VodCleanup::assertWritesAllowed(); \app\common\model\VodCleanup::assertImportJobAllowed($this->job($owner,$id)); return $this->rollbackWithMediaLock($owner,$id); });
        // A fully rolled-back job is finished: an older job may have just left the newest KEEP_FULL_RUNS.
        if (in_array($status['state'] ?? '',['rolled_back','rollback_conflict'],true)) { $this->trimHistoryQuietly(); }
        return $status;
    }

    /** True when retention already removed the details of this job's written items (rollback is impossible). */
    private function journalTrimmed($id)
    {
        return (bool)Db::name('avdb_import_item')->where(['job_id'=>$id,'state'=>['in',['created','updated']],'after_image'=>''])->field('item_id')->find();
    }

    private function rollbackWithMediaLock($owner,$id)
    {
        $current=$this->job($owner,$id); if (in_array($current['state'],['rolled_back','rollback_conflict'],true)) { return $this->status($owner,$id); }
        $provider=self::jobProvider($current);
        // Checked before the lease: a refused rollback changes nothing (no lock, no state, no updated_at).
        if ($this->journalTrimmed($id)) { throw new \RuntimeException(self::TRIMMED_MESSAGE); }
        list($j,$token)=$this->lock($owner,$id);
        $deleted=[];
        try {
            if (in_array($j['state'],['rolled_back','rollback_conflict'],true)) { return $this->status($owner,$id); }
            Db::name('avdb_import_job')->where(['job_id'=>$id,'lock_token'=>$token])->update(['state'=>self::stateFor('paused',$provider),'phase'=>'rollback']);
            $items=Db::name('avdb_import_item')->where(['job_id'=>$id,'state'=>['in',['created','updated']]])->order('item_id desc')->limit(5)->select();
            foreach ($items as $item) {
                Db::startTrans(); try {
                    $this->fence($id,$token); $currentItem=Db::name('avdb_import_item')->where('item_id',$item['item_id'])->lock(true)->find(); if (!$currentItem || !in_array($currentItem['state'],['created','updated'],true)) { Db::commit(); continue; } $item=$currentItem;
                    if ((string)$item['after_image']==='') { throw new \RuntimeException(self::TRIMMED_MESSAGE); }
                    // Compact rows (v2): a created item has no before image (rollback deletes it); the after image is a fingerprint.
                    $before=($item['state']==='created' && (string)$item['before_image']==='') ? ['vod'=>null,'translations'=>[],'adopted'=>false] : $this->decode($item['before_image']); $after=$this->decode($item['after_image']); $current=Db::name('vod')->where('vod_id',$item['vod_id'])->lock(true)->find();
                    $translations=Db::name('avdb_translation')->where('vod_id',$item['vod_id'])->order('locale')->select();
                    if ((int)($after['v'] ?? 0)===2) { $expected=$after['translations'] ?? null; $same=$current && self::stateHash($current)===(string)($after['vod'] ?? '') && ($expected===null || self::stateHash($translations,true)===(string)$expected); }
                    else { $expected=$after['translations'] ?? null; if (is_array($expected)) { usort($expected,function($a,$b){return strcmp($a['locale'],$b['locale']);}); } $same=$current && array_key_exists('vod',$after) && $current==$after['vod'] && ($expected===null || $translations==$expected); }
                    if (!$same || !empty($current['vod_lock']) || !empty($current['vod_recycle_time'])) { throw new \RuntimeException('Rollback conflict: video changed after this import.'); }
                    if ($before['vod']) { Db::name('vod')->where('vod_id',$item['vod_id'])->update($before['vod']); if (!empty($before['adopted'])) { Db::name('avdb_import_map')->where(['provider'=>$provider,'remote_id'=>$item['remote_id'],'vod_id'=>$item['vod_id']])->delete(); } } else { Db::name('vod')->where('vod_id',$item['vod_id'])->delete(); Db::name('avdb_import_map')->where(['provider'=>$provider,'remote_id'=>$item['remote_id'],'vod_id'=>$item['vod_id']])->delete(); $deleted[]=(int)$item['vod_id']; }
                    if ($expected!==null) { Db::name('avdb_translation')->where('vod_id',$item['vod_id'])->delete(); foreach ($before['translations'] as $record) { Db::name('avdb_translation')->insert($record); } }
                    // fp-port-c (FINISH-PORT-REVIEW-b P2-1): the old state may show a video again (or hide / delete it): its
                    // search-queue entry commits with it
                    $this->ftQueueTx([(int)$item['vod_id']]);
                    Db::name('avdb_import_item')->where('item_id',$item['item_id'])->update(['state'=>'rolled_back','message'=>'']); Db::commit(); $this->maintain($current,$before['vod'] ?: []);
                } catch (\Throwable $e) { Db::rollback(); if ($e instanceof AvdbImportLeaseException) { throw $e; } Db::startTrans(); try { $this->fence($id,$token); Db::name('avdb_import_item')->where(['item_id'=>$item['item_id'],'state'=>['in',['created','updated']]])->update(['state'=>'rollback_conflict','message'=>$this->failureMessage($e)]); Db::commit(); } catch (\Throwable $leaseError) { Db::rollback(); throw $leaseError; } }
            }
            if (!Db::name('avdb_import_item')->where(['job_id'=>$id,'state'=>['in',['created','updated']]])->count()) { Db::name('avdb_import_job')->where(['job_id'=>$id,'lock_token'=>$token])->update(['state'=>Db::name('avdb_import_item')->where(['job_id'=>$id,'state'=>'rollback_conflict'])->count()?'rollback_conflict':'rolled_back','phase'=>'complete']); }
        } finally { $this->flushCatalog(); Db::name('avdb_import_job')->where(['job_id'=>$id,'lock_token'=>$token])->update(['lock_token'=>'','lock_until'=>0,'updated_at'=>time()]); }
        if ($deleted) { self::contract('FtIndex','forget',[$deleted]); self::contract('EdgeCache','purge',[$deleted]); self::contract('ScaleCounts','markDirty',['rollback']); }
        // ft-vocab (fp-port): rolled-back videos got their old filter columns back (vod_time kept): queued and caught up now
        if ($this->touched) { self::contract('FilterVocab','imported',[array_values(array_diff($this->touched,$deleted))]); }
        if ($this->touched) { self::contract('FtIndex','touch',[array_values(array_diff($this->touched,$deleted))]); $this->touched=[]; }
        return $this->status($owner,$id);
    }
}

class AvdbImportLeaseException extends \RuntimeException {}
