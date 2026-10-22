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

if (!class_exists(ScaleTier::class, false)) {
    require_once __DIR__ . '/ScaleTier.php';
}
if (!class_exists(PublicRequestGuard::class, false)) {
    require_once __DIR__ . '/PublicRequestGuard.php';
}

/**
 * C10 (owner: lane H). Per-visitor budgets and load-shedding gates. XVID-SCALE-BRIEF 3.2 C10, 2.10 L5 and L6.
 *
 * Contract:
 *   enter(string $class): bool  'search' | 'deep' | 'light'. true = go on (call leave() when done, or let the request end);
 *                               false = shed: the per-key budget is spent, the key already has its in-flight renders, or
 *                               no slot freed up within the wait. The caller serves the stale copy or calls busy().
 *   leave(): void               releases the slot and the in-flight marks of this request (safe when none is held)
 *   busy(): void                503 busy page (about 400 bytes, localized, Retry-After 10-30 s, jittered refresh); no exit
 *
 * Lane H internals:
 *   preCheck(): void            index.php right after an edge miss, before ThinkPHP (about 1-2 ms): counts one page render
 *                               against the key's budget; over budget -> light: the stale copy if one exists, else 429;
 *                               heavy: 429. The 429 page is localized (template_lang guard.too_many) and retries itself.
 *                               A heavy render then takes its gate here (enter(heavyClass)): no slot and no waiter
 *                               place -> the stale copy or the busy page without a framework boot (G11, fixed pools).
 *   classify($tpl, $param)      the class of a template render (All::label_fetch through HP-H-2)
 *
 * Budgets (L5, D10): key = IPv4 /32 or IPv6 /64 (an IPv6 /48 ceiling at 8x); per key per minute heavy (search, filters,
 * page > 50, every after= / cursor= continuation page: XVID-NOLIMIT-BRIEF H5) 300 and light (detail, watch, home, shallow
 * lists) 2,400 (owner decision 2026-10-17: high values; app.budget_heavy / app.budget_light; "Under attack" 30 / 240);
 * counted only on edge misses and only for pages that are rendered: a request that is then shed (busy page, stale copy)
 * gets its count back (refund), so a busy site never spends its visitors' budgets; no site-wide render ceiling.
 * Verified search-engine crawlers (owner decision 2026-10-17, PublicRequestGuard::crawlerVerify: published IP ranges or
 * reverse DNS + forward confirmation, client address from the trusted-proxy rules only) are never limited: no budget, no
 * in-flight cap, no fast shed; every render (light and heavy) takes one of the crawler's own slots (app.crawler_slots, 2;
 * at most CRAWLER_WAITERS wait up to CRAWLER_WAIT_MS = 0.5 s), else it is answered at once with the stale copy or the
 * lighter page, HTTP 200 (verify r2 O-F1c: a crawler at 10 pages / s never takes the whole worker pool). A crawler
 * User-Agent from any other address is an ordinary visitor. A pooled proxy peer (loopback/private/link-local without trusted proxy)
 * has no per-key budget (gates stay on) and raises the proxy notice for the Speed page and tools/upgrade.php. A public peer
 * that sends > 50 % of the misses with > 20 distinct User-Agents within 10 minutes (a host CDN/WAF, or one address rotating
 * User-Agents) raises the same notice and KEEPS its per-key budget (synth decision 15, G12: a lifted budget let one address
 * render 168-203 heavy pages per minute at budget 30 for 24 h); while the flag lasts (UA_LIFT_SECONDS after the last
 * qualifying window) only its in-flight cap is UA_LIFT times higher, so the visitors behind a real CDN render side by side
 * until the admin trusts the proxy (then every visitor gets its own key).
 * Gates (L6): lock-file slots per class (tier: search 3/4, deep 3/2, light 8/6; T3 on <= 2 cores search 2, deep 1), at
 * most 2 waiters for search/deep (wait <= 150 ms search, 300 ms deep) and 6 for light (wait <= 1.5 s), anything else shed
 * at once; per key 1 heavy and 2 light renders in flight. Light first: while as many light renders as CPU cores (>= 2)
 * hold their slots, a heavy render does not start (it waits like a waiter, then sheds).
 * In-flight places (final rehearsal FR-3, decision O-29; owner rule 2026-10-17: per-visitor limits are per visitor, never pooled):
 * the places of a key are the key's own lock files, k/<2 hex>/<PLACE_HEX hex of sha1(key)>-h|l-<i> (placeName()), so only the
 * key's own renders count against its cap; another visitor never takes or blocks them. (Before, the place was one of 4,096 buckets
 * of the key's hash: two different visitors in one bucket refused each other, and since D-14 an idle site answered the second one
 * with the lighter page: HTTP 200, 0 result cards, noindex.) Housekeeping removes place files not taken (heavy) or created (light)
 * for SWEEP_AGE seconds (sweep(); it never takes a place's lock, so it never causes an in-flight refusal).
 * Render-cap mode (verify r3 O-F1c; ScaleTier::gate(): T3 on <= 2 cores, or app.slots_render): every framework render of
 * any class (search, deep, light, verified crawler) also holds one of `render` = 2 x cores tokens (render-l-* only light
 * renders take, render-* every class), so at most 2 x cores renders run at once and `cores` tokens always stay for light
 * pages; at most `waits` = cores requests wait at once (wait places renderwait-*: light pages any, a verified crawler from
 * the second, search / deep from the third: on 2 cores they never wait), light pages with 2 waiter places for 0.3 s. A light render
 * refused for load (no slot / token within its wait) is answered with the stale copy or the lighter page, HTTP 200
 * (EdgeCache::shed, liteOnShed()). Half or more of a small fixed worker pool then stays free for edge hits, victims and shed
 * answers (a 64-connection flood + 2 crawlers at 10 pages / s on 8 workers: 13 x 502 and victims p50 0.86-0.97 s before).
 * flood-r4 O-F1c: while every light token is taken, one key renders at most INFLIGHT_LIGHT_FULL light page at once (its next
 * one gets the stale copy or the lighter page). Measured with a verified crawler + a fake one + a 64-connection search flood on 2
 * cores (victims p50 0.34-0.38 s): the victims' extra time is CPU contention and the fixed pool's single accept queue, not
 * tokens; keeping a verified crawler on render-0 only (also tried) gave the victims nothing (pooled p50 345 -> 347 ms) and cost the
 * crawler 10-20 % of its full pages, so the verified crawler keeps every shared token.
 * Shedding (search-r6 R6-4, flood-r6 O-4; owner decisions D-11 / D-14, 2026-10-19: "turn visitors away only when busy"): the
 * site counts as shedding for SHED_RECENT seconds after a visitor request was refused for LOAD (no slot or render token within
 * its wait, the fast shed of a full heavy class, every light token taken: runtime/gate/shed.at, its mtime written at most
 * once a second; shedding()). Budget, per-key in-flight and crawler-slot refusals are not load and never set it; the fast shed
 * of preCheck() first checks whether the key itself already fills its in-flight places (ownCapFull(): on 2 cores one deep page
 * of the key fills the deep class): that refusal is the key's own cap too, not load. While the
 * site is not shedding: (a) a request refused by its key's own in-flight cap (one heavy / two light renders at once; an
 * unverified crawler such as ClaudeBot sends many at once) gets the stale copy or the lighter page, HTTP 200, instead of the
 * busy page (liteOnShed(); 1.2.0 promotion: 26 x 503 to ClaudeBot at 17 % CPU); (b) a verified crawler's light page that
 * finds the crawler's own slots taken (its slow landings hold them) may also take the crawler's light place
 * (crawler-l-0..CRAWLER_LIGHT_SLOTS-1, with a light render token: the reserve tokens first, so at least one stays for
 * visitors' light pages) and waits like any crawler render (CRAWLER_WAIT_MS) instead of getting the lighter page at once
 * (Googlebot alone at 10 pages / s: 18 of 595 lighter pages, detail / watch / list pages among them). While the site is
 * shedding everything stays as before: in-flight refusals get the busy page, crawler light pages only the crawler's slots.
 * flock unsupported (NFS, some FUSE mounts): fail open with a one-time notice (runtime/gate/flock-unsupported).
 */
final class RequestGate
{
    public const CLASSES = ['search', 'deep', 'light'];
    public const WAITERS = ['search' => 2, 'deep' => 2, 'light' => 6];
    public const WAIT_MS = ['search' => 150, 'deep' => 300, 'light' => 1500];
    public const INFLIGHT = ['heavy' => 1, 'light' => 2];
    /** Budget shard files b/<3 hex>.json. Before FR-3 the in-flight places were bucketed the same way (sweep() removes those files). */
    public const KEY_BUCKETS = 4096;
    /**
     * FR-3 (decision O-29): hex digits of sha1(key) that name a key's in-flight places (placeName(): 64 bits, so two keys share a
     * place with a chance of 2^-64 per pair: the cap counts only the key's own renders).
     */
    public const PLACE_HEX = 16;
    /**
     * FR-3: sweep() removes a place file whose mtime is older than this many seconds (at least heavyHold() + HEAVY_HOLD_SLACK: a heavy
     * place gets the time of the render that took it, so an older one belongs to no render in flight; a light place keeps its
     * creation time). The sweep never takes a place's lock, so a render of the key never finds its place busy because of it.
     */
    public const SWEEP_AGE = 600;
    public const SHARD_MAX = 2048;
    public const UA_WINDOW = 600;
    public const UA_MIN_MISSES = 100;
    public const UA_DISTINCT = 20;
    public const UA_SAMPLE = 3;
    /**
     * A verified crawler's render (light or heavy: verify r2 O-F1c) waits this long for one of its own slots
     * (app.crawler_slots); without one it gets the stale copy, else the lighter page with HTTP 200 (never 429 / 503, never
     * a render without a slot). At most CRAWLER_WAITERS crawler requests wait at the same time; the others get that answer
     * at once, so a fast crawler holds at most crawler_slots + CRAWLER_WAITERS workers.
     */
    public const CRAWLER_WAIT_MS = 500;
    public const CRAWLER_WAITERS = 2;
    /**
     * flood-r4 O-F1c: render-cap mode, while every light render token (render-l-*) is held by other requests (the machine is
     * full), one key renders at most this many light pages at once (INFLIGHT['light'] otherwise): a fake crawler's key held
     * two of the four render tokens of a 2-core machine next to the victims' pages. The refused page gets the stale copy or
     * the lighter page (HTTP 200, liteOnShed()).
     */
    public const INFLIGHT_LIGHT_FULL = 1;
    /**
     * synth decision 15 (G12): a public peer the User-Agent heuristic takes for a host CDN / WAF keeps its per-key budget;
     * for UA_LIFT_SECONDS after its last qualifying window (UA_WINDOW) only its in-flight caps are UA_LIFT times higher.
     */
    public const UA_LIFT = 8;
    public const UA_LIFT_SECONDS = 600;
    /**
     * search-r6 R6-4 / flood-r6 O-4 (owner decisions D-11 / D-14): the site counts as shedding this many seconds after a
     * visitor request was refused for load (markShed(): no slot or render token within its wait, the fast shed, every light
     * token taken). Not load: budget, per-key in-flight and crawler-slot refusals.
     */
    public const SHED_RECENT = 10;
    /**
     * search-r6 R6-4 (D-11 H-R6-4): light places of a verified crawler, used only while the site is not shedding and the
     * crawler's own slots are taken: its light pages (detail, watch, home, shallow lists) are not turned into the lighter page by
     * its own slow landings. Each holds a light render token (the reserve tokens first), so on a 2-core machine at least one of
     * the light-only tokens stays for visitors.
     */
    public const CRAWLER_LIGHT_SLOTS = 1;
    /**
     * flood-r7 R7-2: takeInflight() touches a heavy place file when it takes the place (before the class slot) and enter() sets
     * it back to an old time when the class slot is refused, so only a request that renders leaves a recent mtime; the fast shed
     * of preCheck() probes a key's place only when its file was touched within heavyHold() seconds: the statement limit +
     * HEAVY_HOLD_SLACK (a heavy page ends within statement limit + DbTimeout::DEADLINE_GRACE: O-F1), HEAVY_HOLD_MAX without a
     * statement limit (index.php limits a page to 60 s). Older files belong to no render in flight.
     */
    public const HEAVY_HOLD_MAX = 120;
    public const HEAVY_HOLD_SLACK = 7;
    /**
     * flood-r7 R7-2: the fast shed looks at most this many addresses a request may be keyed by (REMOTE_ADDR, CF-Connecting-IP,
     * X-Real-IP and the X-Forwarded-For entries: who() takes its key from one of them); a request with more asks who() as before.
     */
    public const OWN_ADDR_MAX = 6;

    /** Test double: function ($handle, int $operation, &$wouldBlock): bool replacing flock(). */
    public static $lockFn = null;
    /** Test hook: fixed clock. */
    public static $now = null;

    private static $held = [];           // lock handles of this request (slot, render token, in-flight marks)
    private static $entered = false;     // this request passed enter() already (HO-S-4: a second enter() is free)
    private static $shed = ['why' => '', 'class' => ''];  // the last refusal of enter(): why (budget | inflight | full | slot) and class
    private static $gateMemo = null;     // ScaleTier::gate() of this request
    private static $otherRequests = [];  // test hook: locks kept for a simulated earlier request
    private static $consumed = [];       // budget kinds already counted for this request
    private static $charged = [];        // kind => [[shard, entry, idx, window], ...] counted for this request (refund())
    private static $who = null;          // memo of the client key
    private static $root = null;
    private static $peers = null;        // peers flagged by the User-Agent heuristic
    private static $shedMemo = null;     // shedding() of this request (true once this request marked a load refusal)
    private static $dirMemo = null;      // [site root, runtime/gate/] of this request (flood-r7 R7-2: one is_dir per request, not per call)
    private static $heavyTouched = null; // the heavy place file this request touched (flood-r7 R7-2; set back when it does not render)

    // ----- contract -----

    public static function enter(string $class): bool
    {
        if (!in_array($class, self::CLASSES, true)) {
            $class = 'light';
        }
        // HO-S-4: the controller (search) and label_fetch (HP-H-2) may both gate one render: a request that already passed
        // keeps its slot and in-flight mark, and a second enter() takes no new lock and counts no budget
        if (self::$entered) {
            return true;
        }
        try {
            $kind = $class === 'light' ? 'light' : 'heavy';
            $who = self::who();
            if ($who['crawler'] !== '') {
                $ok = self::enterCrawler($class);
                if (!$ok) { self::$shed = ['why' => 'slot', 'class' => 'crawler']; }
                return $ok;
            }
            // budget (only when preCheck did not count this request already)
            if ($who['budgets'] && !isset(self::$consumed[$kind])) {
                $b = self::consume($kind, $who);
                if (!$b['allowed']) {
                    self::$shed = ['why' => 'budget', 'class' => $class];
                    return false;
                }
            }
            // per-key in-flight cap (flood-r4 O-F1c: a light page beyond INFLIGHT_LIGHT_FULL while every light token is taken is
            // refused as 'full', answered like a light page refused for load; synth decision 15: x UA_LIFT for a flagged peer)
            if ($who['key'] !== '' && $who['budgets']) {
                $in = self::takeInflight($kind, $who['key'], (int)($who['lift'] ?? 1));
                if ($in !== '') {
                    self::refund($kind);
                    self::$shed = ['why' => $in, 'class' => $class];
                    if ($in === 'full') {
                        self::markShed(); // every light token taken: the machine is full (load, not the key's own cap)
                    }
                    return false;
                }
            }
            $ok = self::takeSlot($class);
            self::$entered = $ok;
            if (!$ok) {
                self::refund($kind);
                self::$shed = ['why' => 'slot', 'class' => $class];
                self::markShed();
                self::untouchHeavy();
            }
            return $ok;
        } catch (\Throwable $e) {
            return true; // a gate failure never takes the site down
        }
    }

    /**
     * verify r3 O-F1c: true when the last enter() of this request refused a light render for load (no slot or render token
     * within its wait, or, flood-r4 O-F1c, the key's next light page while every light token is taken: 'full'):
     * EdgeCache::shed() answers it with the stale copy or the lighter page (HTTP 200: a visitor's detail / home / list page
     * is never a bare busy page because the machine is full). Budget refusals stay as they are (429).
     * flood-r6 O-4 (owner decision D-14): also true for a refusal by the key's own in-flight cap ('inflight', heavy or light)
     * while the site is not shedding (shedding()): an unverified crawler or bot that sends several renders at once on an idle
     * site gets the stale copy or the lighter page (HTTP 200) for the extra ones, never the busy page; 503 only while the site
     * is really busy. Verified search engines have no in-flight cap.
     */
    public static function liteOnShed(): bool
    {
        $why = self::$shed['why'];
        if (($why === 'slot' || $why === 'full') && self::$shed['class'] === 'light') {
            return true;
        }
        return $why === 'inflight' && !self::shedding();
    }

    /**
     * search-r6 R6-4 / flood-r6 O-4: true while the site sheds load, i.e. a visitor request was refused for load (markShed())
     * within the last SHED_RECENT seconds (runtime/gate/shed.at: its mtime). One stat per request (remembered); never throws.
     */
    public static function shedding(): bool
    {
        if (self::$shedMemo !== null) {
            return self::$shedMemo;
        }
        try {
            $f = self::dir() . 'shed.at';
            clearstatcache(true, $f);
            $m = @filemtime($f);
            $now = self::$now ?? time();
            // a mark from the future (clock change) counts for at most SHED_RECENT seconds after now as well
            self::$shedMemo = $m !== false && $m >= $now - self::SHED_RECENT && $m <= $now + self::SHED_RECENT;
        } catch (\Throwable $e) {
            self::$shedMemo = false;
        }
        return self::$shedMemo;
    }

    /**
     * A visitor request was refused for load: runtime/gate/shed.at gets the time now as its mtime (written at most once a
     * second: one stat on every refusal, one touch per second while a flood is shed). Never throws.
     */
    private static function markShed(): void
    {
        self::$shedMemo = true;
        try {
            $f = self::dir() . 'shed.at';
            $now = self::$now ?? time();
            clearstatcache(true, $f);
            $m = @filemtime($f);
            if ($m === false || $m < $now || $m > $now + self::SHED_RECENT) {
                if (!@touch($f, $now)) {
                    @file_put_contents($f, '');
                    @touch($f, $now);
                }
            }
        } catch (\Throwable $e) {
        }
    }

    /** The last refusal of enter() in this request: ['why' => '' | 'budget' | 'inflight' | 'full' | 'slot', 'class' => ...]. */
    public static function shedReason(): array
    {
        return self::$shed;
    }

    /**
     * A verified search-engine crawler (owner decision 2026-10-17): never a budget or in-flight refusal, never 429 / 503. Every
     * render (light and heavy, verify r2 O-F1c) takes one of the crawler's own slots (app.crawler_slots, default 2): a free
     * one at once, else one of CRAWLER_WAITERS waiter places and up to CRAWLER_WAIT_MS of waiting. false = no slot: the
     * caller answers with the stale copy, else EdgeCache::lite() (HTTP 200, indexable for the crawler). A crawler never
     * renders without a slot and never waits seconds inside a worker, so it cannot take the whole worker pool.
     */
    private static function enterCrawler(string $class): bool
    {
        $dir = self::dir() . 's/';
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        $n = max(1, (int)(ScaleTier::settings()['crawler_slots'] ?? ScaleTier::CRAWLER_SLOTS));
        $g = self::gateCfg();
        // search-r6 R6-4 (owner decision D-11, H-R6-4): while the site is not shedding, a light page of the crawler may also take
        // the crawler's light place (crawler-l-*, a light render token: the reserve tokens first) when its own slots are held
        // by its slow landings, and waits for either; while shedding it keeps to the crawler's slots as before
        $lightPlace = $class === 'light' && !self::shedding();
        // light first (as for heavy visitor renders): while visitors' light renders hold as many slots as there are cores,
        // a crawler render does not start (it waits like a waiter, then gets the lighter page): visitors stay fast on a small
        // machine and the crawler still gets HTTP 200 (verify r2 O-F1c); in render-cap mode it also needs a shared render
        // token (verify r3 O-F1c: never one of the tokens kept for light pages; the first shared token is kept for crawlers and
        // light pages, so a search flood cannot keep a verified crawler on the lighter page)
        $take = function () use ($dir, $n, $g, $lightPlace): ?bool {
            $r = self::acquire($dir, 'crawler-', $n, true, 'crawler', $g);
            if ($r !== false || !$lightPlace) {
                return $r;
            }
            return self::acquire($dir, 'crawler-l-', self::CRAWLER_LIGHT_SLOTS, false, 'light', $g);
        };
        $r = $take();
        if ($r !== false) {
            self::$entered = true;   // a slot (+ token), or flock unsupported (fail open)
            return true;
        }
        $w = self::tryAny($dir . 'crawler-wait-', self::CRAWLER_WAITERS);
        if ($w === false && $lightPlace) {
            // search-r6 R6-4: the crawler's slow landings can hold both waiter places; its light page keeps one of its own
            $w = self::tryAny($dir . 'crawler-l-wait-', self::CRAWLER_LIGHT_SLOTS);
        }
        if ($w === false) {
            return false;            // every slot and waiter place taken: stale copy / lighter page at once
        }
        $waiter = $w === true ? array_pop(self::$held) : null;
        // render-cap mode: one of the global wait places too (a verified crawler: every place after the first, which is kept
        // for visitors' light pages; search-r6 R6-4: a light page while the site is not shedding any place, like a visitor's
        // light page: on an idle site visitors' light pages rarely wait, one light token always stays for them)
        $place = null;
        if ($g['render'] > 0) {
            $p = self::takeWaitPlace($dir, $lightPlace ? 'light' : 'crawler', $g);
            if ($p === false) {
                self::release($waiter);
                return false;
            }
            $place = $p === true ? array_pop(self::$held) : null;
        }
        $deadline = microtime(true) + self::CRAWLER_WAIT_MS / 1000;
        $got = false;
        while (microtime(true) < $deadline) {
            usleep(20000);
            if ($take() !== false) {
                $got = true;
                break;
            }
        }
        self::release($waiter);
        self::release($place);
        self::$entered = $got;
        return $got;
    }

    /** The verified crawler this request comes from ('google', 'bing', ... or '' for everyone else). Never throws. */
    public static function crawler(): string
    {
        try {
            return (string)(self::who()['crawler'] ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    public static function leave(): void
    {
        // flood-r6 O-4: newest first (render token, class slot, then the in-flight marks), so another request of the key never
        // sees its in-flight mark free while the slot / token of the ending render is still held (a full class would then look
        // like load to the fast shed of preCheck())
        foreach (array_reverse(self::$held) as $h) {
            @flock($h, LOCK_UN);
            @fclose($h);
        }
        self::$held = [];
        self::$entered = false;
    }

    /** Test hook: start a "new request" in this process while the current one keeps its locks (they stay held). */
    public static function newRequestForTest(): void
    {
        self::$otherRequests[] = self::$held;
        self::$held = [];
        self::$entered = false;
        self::$consumed = [];
        self::$charged = [];
        self::$who = null;
        self::$shed = ['why' => '', 'class' => ''];
        self::$gateMemo = null;
        self::$shedMemo = null;
        self::$dirMemo = null;
        self::$heavyTouched = null;
    }

    /** Number of gate / in-flight locks this request holds (housekeeping checks it is 0 before it runs, L12). */
    public static function held(): int
    {
        return count(self::$held);
    }

    public static function busy(): void
    {
        $retry = random_int(10, 30);
        $text = self::text('guard.busy', 'The site is busy. Retrying in {s} s.', $retry);
        if (!headers_sent()) {
            http_response_code(503);
            header_remove('Set-Cookie');
            header('Retry-After: ' . $retry, true);
            header('Content-Type: text/html; charset=utf-8', true);
            header('Cache-Control: no-store', true);
            header('X-Robots-Tag: noindex', true);
            header('X-Edge-Cache: SHED', true);
        }
        echo self::page($retry, $text);
    }

    // ----- pre-framework budget check -----

    public static function preCheck(string $root = ''): void
    {
        if ($root !== '') {
            self::$root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        }
        try {
            $kind = self::classifyUri($_SERVER, $_GET);
            if ($kind === '') {
                return;
            }
            // a verified search-engine crawler is never limited here (owner decision 2026-10-17): every render (light and
            // heavy) takes one of its own slots before the framework boots; without one (after at most CRAWLER_WAIT_MS) it gets
            // the stale copy, else the lighter page with HTTP 200 (verify r2 O-F1c: a crawler never renders without a slot, and
            // a refused crawler request costs about 2 ms of a worker instead of a framework boot); its light pages may also take
            // the crawler's light place while the site is not shedding (search-r6 R6-4). Only a crawler User-Agent costs the
            // verification (a regex, then one small file read).
            if (self::claimsCrawler() && self::who()['crawler'] !== '') {
                if (!self::enter($kind === 'heavy' ? self::heavyClass($_SERVER, $_GET) : 'light')) {
                    if (class_exists(EdgeCache::class, false) && EdgeCache::emitStale()) {
                        exit;
                    }
                    if (class_exists(EdgeCache::class, false)) {
                        EdgeCache::lite();
                        exit;
                    }
                }
                return;
            }
            // fast shed (G11 / G3b): while every slot and every waiter place of the heavy class is taken, the request is shed
            // before the client key, the budget shard write and the in-flight marks (about half the CPU of a shed answer;
            // a flood is answered at hundreds per second on 2 cores, next to the victims' pages)
            $hc = $kind === 'heavy' ? self::heavyClass($_SERVER, $_GET) : '';
            if ($kind === 'heavy' && self::allBusy($hc)) {
                // flood-r6 O-4: when the class is full while this key already has its heavy render(s) in flight (on 2 cores one
                // deep page fills the deep class), the request is refused by the key's own in-flight cap, not by load: no shed
                // mark, and on a site that is not shedding the stale copy or the lighter page (the D-14 answer of enter()); a
                // class that freed while we looked (a render just ended) is not shed at all: the normal path below decides.
                // flood-r7 R7-2: fastShed() asks for the client key (who()) only when one of the request's addresses has a heavy
                // render in flight, and re-checks the class only when such a render may have just ended (fastShed())
                $verdict = self::fastShed($hc);
                $own = $verdict === 'own';
                if ($verdict !== '') {
                    if ($own) {
                        self::$shed = ['why' => 'inflight', 'class' => $hc];
                    } elseif ($verdict === 'load') {
                        self::markShed();
                    }
                    if (class_exists(EdgeCache::class, false) && EdgeCache::emitStale()) {
                        exit;
                    }
                    if ($own && self::liteOnShed() && class_exists(EdgeCache::class, false)) {
                        EdgeCache::lite();
                        exit;
                    }
                    self::busy();
                    exit;
                }
            }
            $who = self::who();
            self::observe($who);
            if ($who['budgets']) {
                $b = self::consume($kind, $who);
                if (!$b['allowed']) {
                    if ($kind === 'light' && class_exists(EdgeCache::class, false) && EdgeCache::emitStale()) {
                        exit;
                    }
                    self::tooMany((int)$b['retry']);
                    exit;
                }
            }
            // L6 gate before the framework for heavy renders (G11): the slot (or one of the few waiter places, then the
            // slot) is taken here and kept for the whole request, so the framework's enter() is free (HO-S-4 rule). A heavy
            // request without a slot is shed in ~2 ms instead of after a ThinkPHP boot (~40 ms of a worker), and waiter
            // places are taken atomically (no herd of boots behind each freed place): a search flood cannot fill a fixed
            // worker pool's queue in front of light pages. Light pages keep their gate (and longer wait) in the framework.
            if ($kind === 'heavy' && !self::enter(self::heavyClass($_SERVER, $_GET))) {
                if (class_exists(EdgeCache::class, false) && EdgeCache::emitStale()) {
                    exit;
                }
                // flood-r6 O-4 (owner decision D-14): the key's own in-flight cap on a site that is not shedding -> the lighter
                // page (HTTP 200, noindex for visitors) instead of the busy page; load refusals keep the busy page
                if (self::liteOnShed() && class_exists(EdgeCache::class, false)) {
                    EdgeCache::lite();
                    exit;
                }
                self::busy();
                exit;
            }
        } catch (\Throwable $e) {
            return;
        }
    }

    /** The User-Agent claims one of the configured crawlers (a claim only: who() verifies it). */
    private static function claimsCrawler(): bool
    {
        $engines = ScaleTier::settings()['crawlers'] ?? [];
        return is_array($engines) && $engines && PublicRequestGuard::crawlerClaim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), $engines) !== '';
    }

    /** '' (not a page render: ajax, member, ads, feeds of their own), 'heavy' or 'light', from the URI alone. */
    public static function classifyUri(array $s, array $get): string
    {
        $uri = (string)($s['REQUEST_URI'] ?? '');
        [$path] = explode('?', $uri, 2);
        $p = strtolower(rawurldecode($path));
        $p = preg_replace('~^/index\.php~', '', $p);
        $p = preg_replace('~^/[a-z]{2}(?:-[a-z]{2})?/~', '/', $p);
        if (preg_match('~^/(?:user|ajax|jcx|verify|comment|gbook|payment|static|upload|template|api\.php|install|avdbschedule|translateapi|receive|wechat|favicon|robots\.txt|sitemap)~', $p)) {
            return '';
        }
        $method = strtoupper((string)($s['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD') {
            return '';
        }
        if (strpos($p, '/search') !== false || strpos($p, 'vod/search') !== false || isset($get['wd'])) {
            return 'heavy';
        }
        foreach (['tag', 'actor', 'area', 'year', 'letter', 'lang', 'class', 'director'] as $f) {
            if (isset($get[$f]) && $get[$f] !== '') {
                return 'heavy';
            }
        }
        if (isset($get['by']) && is_string($get['by']) && $get['by'] !== '' && !in_array(strtolower($get['by']), ['time', 'time_add'], true)) {
            return 'heavy';
        }
        if (preg_match('~^/show/~', $p) && preg_match('~/(?:tag|actor|area|year|letter|lang|class|director|by)/~', $p)) {
            return 'heavy';
        }
        // H5: continuation pages (after= on lists, cursor= on searches) are deep at any position
        if (self::continuation($get)) {
            return 'heavy';
        }
        $page = isset($get['page']) && is_string($get['page']) && ctype_digit($get['page']) ? (int)$get['page'] : 0;
        if (preg_match('~/page/(\d{1,9})~', $p, $m)) {
            $page = max($page, (int)$m[1]);
        }
        if (preg_match('~^/(?:category/[^/]+|index)-(\d{1,9})/?$~', $p, $m)) {
            $page = max($page, (int)$m[1]);
        }
        return $page > 50 ? 'heavy' : 'light';
    }

    /** H5: the request carries an after= or cursor= continuation (any value: a junk value is still not a cheap page). */
    public static function continuation(array $get): bool
    {
        foreach (['after', 'cursor'] as $k) {
            if (isset($get[$k]) && $get[$k] !== '' && $get[$k] !== []) {
                return true;
            }
        }
        return false;
    }

    /**
     * HO-P-11 (D-I11): true when every 'deep' slot is held right now by other requests (non-blocking probe; a free slot is
     * released at once). The import CLI waits while it is true. Missing slot files, flock unsupported or any error -> false.
     */
    public static function deepBusy(): bool
    {
        try {
            $dir = self::dir() . 's/';
            $n = (int)(ScaleTier::get()['slots']['deep'] ?? 1);
            for ($i = 0; $i < $n; $i++) {
                if (!is_file($dir . 'deep-' . $i)) {
                    return false;
                }
                $h = @fopen($dir . 'deep-' . $i, 'c');
                if (!$h) {
                    return false;
                }
                $r = self::lock($h, 0);
                if ($r === true) {
                    @flock($h, LOCK_UN);
                }
                fclose($h);
                if ($r !== false) {
                    return false; // free (or flock unsupported: never make the importer wait)
                }
            }
            return $n > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Gate class of a URI that classifyUri() called heavy: 'search' (search routes or wd=) or 'deep'. */
    public static function heavyClass(array $s, array $get): string
    {
        [$path] = explode('?', (string)($s['REQUEST_URI'] ?? ''), 2);
        $p = strtolower(rawurldecode($path));
        return (strpos($p, '/search') !== false || strpos($p, 'vod/search') !== false || isset($get['wd'])) ? 'search' : 'deep';
    }

    /** Class of a template render (HP-H-2): search templates, filtered / deep / sorted lists, everything else. '' = no gate. */
    public static function classify(string $tplPath, array $param): string
    {
        $t = strtolower(str_replace('\\', '/', $tplPath));
        if (strpos($t, 'public/') === 0) {
            return '';
        }
        if (preg_match('~(?:^|/)(?:vod|art|actor|topic|role|website)/(?:search|ajax_search)$~', $t)) {
            return 'search';
        }
        foreach (['tag', 'actor', 'area', 'year', 'letter', 'lang', 'class', 'director'] as $f) {
            if (isset($param[$f]) && is_string($param[$f]) && $param[$f] !== '') {
                return 'deep';
            }
        }
        $by = $param['by'] ?? '';
        if (is_string($by) && $by !== '' && !in_array(strtolower($by), ['time', 'time_add'], true)) {
            return 'deep';
        }
        if ((int)($param['page'] ?? 1) > 50) {
            return 'deep';
        }
        // H5: after= / cursor= continuation pages (mac_param_url() does not carry them: the query string is read too)
        if (self::continuation($param) || self::continuation($_GET)) {
            return 'deep';
        }
        return 'light';
    }

    // ----- budgets -----

    /** The client key: ['key' => '4:1.2.3.4' | '6:<64 bits>', 'ceiling' => '' | '6c:<48 bits>', 'budgets' => bool, ...]. */
    public static function who(?array $server = null): array
    {
        if ($server === null && self::$who !== null) {
            return self::$who;
        }
        $s = $server ?? $_SERVER;
        $settings = ScaleTier::settings();
        $info = PublicRequestGuard::peerInfo(ScaleTier::root(), $s, ['tunnel' => !empty($settings['cf_tunnel'])]);
        $out = ['key' => '', 'ceiling' => '', 'budgets' => (bool)$info['budgets'], 'ip' => $info['ip'], 'peer' => $info['peer'],
            'via' => $info['via'], 'header' => $info['header'], 'crawler' => '', 'lift' => 1];
        $packed = $info['ip'] !== '' ? @inet_pton($info['ip']) : false;
        if ($packed !== false && strlen($packed) === 4) {
            $out['key'] = '4:' . $info['ip'];
        } elseif ($packed !== false && strlen($packed) === 16) {
            $out['key'] = '6:' . bin2hex(substr($packed, 0, 8));
            $out['ceiling'] = '6c:' . bin2hex(substr($packed, 0, 6));
        } else {
            $out['budgets'] = false;
        }
        // the address that reaches us directly, or the one a trusted proxy reports (a host CDN behind the trusted proxy): a peer
        // the User-Agent heuristic flagged keeps its per-key budget (synth decision 15, G12: one address rotating User-Agents
        // must not lift its own budget); while the flag lasts its in-flight caps are UA_LIFT x (real CDN visitors render side
        // by side) and the proxy notice asks the admin to trust the proxy
        if ($out['budgets'] && in_array($info['via'], ['direct', 'proxy'], true) && self::flaggedPeer($info['ip'])) {
            $out['lift'] = self::UA_LIFT;
        }
        // verified search-engine crawler: the address the trusted-proxy rules give (never a raw X-Forwarded-For /
        // CF-Connecting-IP from an untrusted peer; a pooled peer's own address is private and never verifies)
        $engines = is_array($settings['crawlers'] ?? null) ? $settings['crawlers'] : [];
        if ($engines && $out['key'] !== '' && $info['via'] !== 'pooled') {
            $engine = PublicRequestGuard::crawlerClaim((string)($s['HTTP_USER_AGENT'] ?? ''), $engines);
            if ($engine !== '' && PublicRequestGuard::crawlerVerify(ScaleTier::root(), $engine, $info['ip'], (array)$engines[$engine])) {
                $out['crawler'] = $engine;
                $out['budgets'] = false;
            }
        }
        if ($server === null) {
            self::$who = $out;
        }
        return $out;
    }

    /**
     * Count one render of $kind ('heavy'|'light') for the key (and its /48 ceiling). ['allowed' => bool, 'retry' => s].
     * Fixed one-minute windows in 4,096 small shard files; a busy or full shard lets the request through (budgets protect,
     * the gates and the statement limit are the hard limits).
     */
    public static function consume(string $kind, array $who, ?int $now = null): array
    {
        $now = $now ?? (self::$now ?? time());
        self::$consumed[$kind] = true;
        if (!$who['budgets'] || $who['key'] === '') {
            return ['allowed' => true, 'retry' => 0];
        }
        $budget = ScaleTier::get()['budget'];
        $limit = $kind === 'heavy' ? (int)$budget['heavy'] : (int)$budget['light'];
        $idx = $kind === 'heavy' ? 0 : 1;
        $window = intdiv($now, 60) * 60 + 60;
        $h = sha1($who['key']);
        $ok = self::bump(substr($h, 0, 3), substr($h, 3, 16), $idx, $limit, $window, $now);
        if ($ok === false) {
            self::logDenied($who['key'], $now);
            return ['allowed' => false, 'retry' => max(1, $window - $now)];
        }
        $charged = $ok === true ? [[substr($h, 0, 3), substr($h, 3, 16), $idx, $window]] : [];
        if ($who['ceiling'] !== '') {
            $hc = sha1($who['ceiling']);
            $okc = self::bump(substr($hc, 0, 3), substr($hc, 3, 16), $idx, $limit * 8, $window, $now);
            if ($okc === false) {
                self::logDenied($who['ceiling'], $now);
                self::$charged[$kind] = $charged;
                self::refund($kind);
                return ['allowed' => false, 'retry' => max(1, $window - $now)];
            }
            if ($okc === true) {
                $charged[] = [substr($hc, 0, 3), substr($hc, 3, 16), $idx, $window];
            }
        }
        self::$charged[$kind] = $charged;
        return ['allowed' => true, 'retry' => 0];
    }

    /**
     * Give back the budget count of this request ($kind 'heavy' | 'light') when it is not rendered after all (shed: no slot,
     * in-flight cap, busy page or stale copy). Only the entries counted by this request, only in the same window. Never throws.
     */
    public static function refund(string $kind): void
    {
        $list = self::$charged[$kind] ?? [];
        unset(self::$charged[$kind]);
        foreach ($list as $c) {
            try {
                self::bump($c[0], $c[1], (int)$c[2], PHP_INT_MAX, (int)$c[3], 0, -1);
            } catch (\Throwable $e) {
            }
        }
    }

    /** true counted, false over the limit, null not counted (busy/full/unsupported: let it through). $delta -1 = refund. */
    private static function bump(string $shard, string $entry, int $idx, int $limit, int $window, int $now, int $delta = 1): ?bool
    {
        $dir = self::dir() . 'b/';
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        $fh = @fopen($dir . $shard . '.json', 'c+');
        if (!$fh) {
            return null;
        }
        try {
            $l = self::lock($fh, 50);
            if ($l !== true) {
                return null;
            }
            $raw = stream_get_contents($fh);
            $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            if (!is_array($state) || !isset($state['w'], $state['e']) || !is_array($state['e']) || (int)$state['w'] !== $window) {
                if ($delta < 0) {
                    @flock($fh, LOCK_UN);
                    return null; // the window has moved on: nothing to give back
                }
                $state = ['w' => $window, 'e' => []];
            }
            $cur = $state['e'][$entry] ?? [0, 0];
            if (!is_array($cur) || count($cur) !== 2) { $cur = [0, 0]; }
            if ($delta < 0) {
                if (!isset($state['e'][$entry]) || (int)$cur[$idx] <= 0) {
                    @flock($fh, LOCK_UN);
                    return null;
                }
                $cur[$idx] = (int)$cur[$idx] - 1;
                $state['e'][$entry] = [(int)$cur[0], (int)$cur[1]];
                $bytes = json_encode($state, JSON_UNESCAPED_SLASHES);
                ftruncate($fh, 0);
                rewind($fh);
                fwrite($fh, $bytes);
                fflush($fh);
                @flock($fh, LOCK_UN);
                return true;
            }
            if ((int)$cur[$idx] >= $limit) {
                @flock($fh, LOCK_UN);
                return false;
            }
            if (!isset($state['e'][$entry]) && count($state['e']) >= self::SHARD_MAX) {
                @flock($fh, LOCK_UN);
                return null;
            }
            $cur[$idx] = (int)$cur[$idx] + 1;
            $state['e'][$entry] = [(int)$cur[0], (int)$cur[1]];
            $bytes = json_encode($state, JSON_UNESCAPED_SLASHES);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $bytes);
            fflush($fh);
            @flock($fh, LOCK_UN);
            return true;
        } finally {
            fclose($fh);
        }
    }

    /** 429 page (localized, automatic retry). No framework, about 1 KB. */
    public static function tooMany(int $retry): void
    {
        $retry = max(1, min(60, $retry));
        $text = self::text('guard.too_many', 'Too many pages in a minute from your network. Retrying in {s} s.', $retry);
        if (!headers_sent()) {
            http_response_code(429);
            header_remove('Set-Cookie');
            header('Retry-After: ' . $retry, true);
            header('Content-Type: text/html; charset=utf-8', true);
            header('Cache-Control: no-store', true);
            header('X-Robots-Tag: noindex', true);
            header('X-Edge-Cache: LIMIT', true);
        }
        echo self::page($retry, $text);
    }

    private static function page(int $retry, string $text): string
    {
        $jitter = $retry + random_int(0, 5);
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="refresh" content="' . $jitter . '">'
            . '<title>' . $retry . ' s</title></head><body style="font:16px system-ui,sans-serif;margin:3em auto;max-width:32em;text-align:center">'
            . '<p dir="auto">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
    }

    /** A guard.* string of template_lang (HO-H-3, lane T) in the visitor's language; English fallback. {s} = seconds. */
    private static function text(string $key, string $fallback, int $seconds): string
    {
        $s = self::catalog($key);
        if ($s === '' || strpos($s, '{s}') === false) {
            $s = $fallback;
        }
        return str_replace('{s}', (string)$seconds, $s);
    }

    /** A guard.* string without placeholders (the lighter page of EdgeCache::lite); $fallback when the catalog has none. */
    public static function phrase(string $key, string $fallback): string
    {
        try {
            $s = self::catalog($key);
        } catch (\Throwable $e) {
            $s = '';
        }
        return $s !== '' ? $s : $fallback;
    }

    /** The visitor's two-letter language: the avdb_ui_lang cookie, ?ui_lang=, else the site language. */
    public static function locale(): string
    {
        $c = $_COOKIE['avdb_ui_lang'] ?? ($_GET['ui_lang'] ?? '');
        if (is_string($c) && preg_match('/^[a-z]{2}$/', $c)) {
            return $c;
        }
        return substr((string)(ScaleTier::settings()['lang'] ?? 'en'), 0, 2);
    }

    /** The template_lang catalog value of $key in the visitor's language ('' when there is none). Cached in strings.json. */
    private static function catalog(string $key): string
    {
        $locale = '';
        $c = $_COOKIE['avdb_ui_lang'] ?? ($_GET['ui_lang'] ?? '');
        if (is_string($c) && preg_match('/^[a-z]{2}$/', $c)) {
            $locale = $c;
        }
        if ($locale === '') {
            $locale = substr((string)(ScaleTier::settings()['lang'] ?? 'en'), 0, 2);
        }
        $cache = ScaleTier::readJson(self::dir() . 'strings.json') ?: [];
        $id = $locale . '|' . $key;
        // catalog format (HO-T-6): en.json maps the key to the English text; every other <locale>.json maps that English
        // text to the translation
        $dirLang = ScaleTier::root() . 'application/common/template_lang/';
        $file = $dirLang . $locale . '.json';
        $mtime = (is_file($file) ? (int)filemtime($file) : 0) + (is_file($dirLang . 'en.json') ? (int)filemtime($dirLang . 'en.json') : 0);
        if (!isset($cache[$id]) || (int)($cache[$id][1] ?? -1) !== $mtime) {
            $value = '';
            $en = is_file($dirLang . 'en.json') ? json_decode((string)file_get_contents($dirLang . 'en.json'), true) : null;
            $english = is_array($en) && isset($en[$key]) && is_string($en[$key]) ? $en[$key] : '';
            if ($locale === 'en' || $english === '') {
                $value = $english;
            } elseif (is_file($file)) {
                $all = json_decode((string)file_get_contents($file), true);
                $value = is_array($all) && isset($all[$english]) && is_string($all[$english]) ? $all[$english] : $english;
            }
            $cache[$id] = [$value, $mtime];
            if (count($cache) <= 200) {
                ScaleTier::writeAtomic(self::dir() . 'strings.json', json_encode($cache, JSON_UNESCAPED_UNICODE));
            }
        }
        return (string)$cache[$id][0];
    }

    // ----- gates -----

    private static function takeSlot(string $class): bool
    {
        $slots = (int)(ScaleTier::get()['slots'][$class] ?? 1);
        $dir = self::dir() . 's/';
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        $heavy = $class !== 'light';
        $g = self::gateCfg();
        // a class slot (light pages first for heavy renders, G11), plus a render token in render-cap mode (verify r3 O-F1c)
        $r = self::acquire($dir, $class . '-', $slots, $heavy, $heavy ? 'heavy' : 'light', $g);
        if ($r !== false) {
            return true; // taken, or flock unsupported (fail open)
        }
        // no free slot or token: become one of the few waiters (and, in render-cap mode, take one of the global wait places:
        // light pages any, search / deep only from the third one on), else shed at once
        $w = self::tryAny($dir . $class . '-wait-', self::waiters($class, $g));
        if ($w === false) {
            return false;
        }
        $waiter = $w === true ? array_pop(self::$held) : null;
        $place = null;
        if ($g['render'] > 0) {
            $p = self::takeWaitPlace($dir, $heavy ? 'heavy' : 'light', $g);
            if ($p === false) {
                self::release($waiter);
                return false;
            }
            $place = $p === true ? array_pop(self::$held) : null;
        }
        $deadline = microtime(true) + self::waitMs($class, $g) / 1000;
        $got = false;
        while (microtime(true) < $deadline) {
            usleep(10000);
            if (self::acquire($dir, $class . '-', $slots, $heavy, $heavy ? 'heavy' : 'light', $g) !== false) {
                $got = true;
                break;
            }
        }
        self::release($waiter);
        self::release($place);
        return $got;
    }

    /** ScaleTier::gate() once per request (render tokens, reserve, wait places, light waiters; verify r3 O-F1c). */
    private static function gateCfg(): array
    {
        if (self::$gateMemo === null) {
            try {
                self::$gateMemo = ScaleTier::gate();
            } catch (\Throwable $e) {
                self::$gateMemo = ['render' => 0, 'reserve' => 0, 'waits' => 0, 'light_waiters' => 0, 'light_wait_ms' => 0];
            }
        }
        return self::$gateMemo;
    }

    /** Waiter places of a class: WAITERS, light pages in render-cap mode ScaleTier::CAP_LIGHT_WAITERS. */
    private static function waiters(string $class, array $g): int
    {
        if ($class === 'light' && (int)($g['light_waiters'] ?? 0) > 0) {
            return (int)$g['light_waiters'];
        }
        return (int)(self::WAITERS[$class] ?? 0);
    }

    /** Longest wait of a class: WAIT_MS, light pages in render-cap mode ScaleTier::CAP_LIGHT_WAIT_MS. */
    private static function waitMs(string $class, array $g): int
    {
        if ($class === 'light' && (int)($g['light_wait_ms'] ?? 0) > 0) {
            return (int)$g['light_wait_ms'];
        }
        return (int)(self::WAIT_MS[$class] ?? 0);
    }

    /**
     * One class slot <prefix>0..n-1 and, in render-cap mode, one render token (verify r3 O-F1c); both are kept in $held for
     * the request. $lightFirst: a heavy / crawler render gives its slot back while light renders hold as many slots as there
     * are cores (G11). $kind: 'light' (may also take the tokens kept for light pages), 'crawler' (a verified crawler: every
     * shared token) or 'heavy' (search / deep: the shared tokens after the first, see takeToken). true = taken, false = no
     * slot / no token (nothing kept), null = flock unsupported (fail open).
     */
    private static function acquire(string $dir, string $prefix, int $slots, bool $lightFirst, string $kind, array $g): ?bool
    {
        $r = self::tryAny($dir . $prefix, $slots);
        if ($r !== true) {
            return $r;
        }
        if ($lightFirst && self::lightFirst($dir)) {
            self::release(array_pop(self::$held));
            return false;
        }
        if ((int)($g['render'] ?? 0) > 0) {
            $t = self::takeToken($dir, $kind, $g);
            if ($t === false) {
                self::release(array_pop(self::$held));
                return false;
            }
        }
        return true;
    }

    /**
     * A render token (verify r3 O-F1c): light renders try the `reserve` tokens kept for them (render-l-*) first, then the
     * shared ones (render-*); a verified crawler every shared token; search / deep renders the shared tokens from
     * heavyTokenFrom() on (the first shared token stays for crawlers and light pages: a search flood held every shared token
     * and kept a verified crawler on the lighter page in 325 of 595 answers). true = held (pushed to $held), false = none
     * free, null = flock unsupported.
     */
    private static function takeToken(string $dir, string $kind, array $g): ?bool
    {
        $render = max(0, (int)($g['render'] ?? 0));
        $reserve = max(0, min($render - 1, (int)($g['reserve'] ?? 0)));
        if ($kind === 'light' && $reserve > 0) {
            $r = self::tryAny($dir . 'render-l-', $reserve);
            if ($r !== false) {
                return $r;
            }
        }
        $shared = max(0, $render - $reserve);
        return self::tryAny($dir . 'render-', $shared, $kind === 'heavy' ? self::heavyTokenFrom($shared) : 0);
    }

    /** First shared render token a search / deep render may take: 1 when there are at least two shared tokens, else 0. */
    private static function heavyTokenFrom(int $shared): int
    {
        return $shared >= 2 ? 1 : 0;
    }

    /**
     * One of the `waits` global wait places of render-cap mode (verify r3 O-F1c): visitors' light pages may take any of them,
     * a verified crawler every place from the second on, search / deep renders only from the third on (on a 2-core machine:
     * place 0 for light pages, place 1 for light pages and the crawler; search / deep never wait there: they are shed at
     * once, so a flood cannot park workers in waits). true = held (pushed to $held), false = none free, null = flock
     * unsupported.
     */
    private static function takeWaitPlace(string $dir, string $kind, array $g): ?bool
    {
        $waits = max(0, (int)($g['waits'] ?? 0));
        for ($i = self::waitPlaceFrom($kind); $i < $waits; $i++) {
            $h = @fopen($dir . 'renderwait-' . $i, 'c');
            if (!$h) {
                continue;
            }
            $r = self::lock($h, 0);
            if ($r === true) {
                self::$held[] = $h;
                return true;
            }
            fclose($h);
            if ($r === null) {
                self::flockUnsupported();
                return null;
            }
        }
        return false;
    }

    /** First global wait place a kind of render may take: light 0, crawler 1, heavy (search / deep) 2. */
    private static function waitPlaceFrom(string $kind): int
    {
        return $kind === 'light' ? 0 : ($kind === 'crawler' ? 1 : 2);
    }

    /** Unlock and close one lock handle (null: nothing). */
    private static function release($h): void
    {
        if ($h) {
            @flock($h, LOCK_UN);
            @fclose($h);
        }
    }

    /**
     * true when every slot and every waiter place of $class is held by other requests right now (non-blocking probe; a free
     * lock is released at once). Missing lock files, flock unsupported or any error -> false (the normal path decides).
     */
    private static function allBusy(string $class): bool
    {
        if (self::$entered || self::$lockFn !== null) {
            return false;
        }
        $dir = self::dir() . 's/';
        $slots = (int)(ScaleTier::get()['slots'][$class] ?? 1);
        $g = self::gateCfg();
        $names = [];
        for ($i = 0; $i < $slots; $i++) { $names[] = $class . '-' . $i; }
        $waiters = [];
        for ($i = 0; $i < self::waiters($class, $g); $i++) { $waiters[] = $class . '-wait-' . $i; }
        if ((int)($g['render'] ?? 0) <= 0) {
            return self::allHeld($dir, array_merge($names, $waiters));
        }
        // render-cap mode (verify r3 O-F1c): no render without a class slot AND one of the shared tokens a heavy render may
        // take (heavyTokenFrom on), no wait without a waiter place of the class AND one of the global wait places a heavy
        // render may take (from the third on)
        $tokens = [];
        $shared = max(0, (int)$g['render'] - (int)$g['reserve']);
        for ($i = self::heavyTokenFrom($shared); $i < $shared; $i++) { $tokens[] = 'render-' . $i; }
        $places = [];
        for ($i = self::waitPlaceFrom('heavy'); $i < (int)$g['waits']; $i++) { $places[] = 'renderwait-' . $i; }
        $noRender = self::allHeld($dir, $names) || ($tokens !== [] && self::allHeld($dir, $tokens)) || $tokens === [];
        if (!$noRender) {
            return false;
        }
        return self::allHeld($dir, $waiters) || $places === [] || self::allHeld($dir, $places);
    }

    /**
     * true when every one of the lock files $names in $dir is held by another request right now (non-blocking probe, a free
     * lock is released at once). A missing file, flock unsupported or an error -> false (the normal path decides).
     */
    private static function allHeld(string $dir, array $names): bool
    {
        if (!$names) {
            return false;
        }
        foreach ($names as $n) {
            if (!is_file($dir . $n)) {
                return false;
            }
            $h = @fopen($dir . $n, 'c');
            if (!$h) {
                return false;
            }
            $r = self::lock($h, 0);
            if ($r === true) {
                @flock($h, LOCK_UN);
            }
            fclose($h);
            if ($r !== false) {
                return false;
            }
        }
        return true;
    }

    /**
     * true when at least LIGHT_FIRST light renders hold their gate slot right now (non-blocking probe of the light slot
     * files; a free slot is released at once). LIGHT_FIRST = the CPU cores (at least 2; 4 when unknown).
     */
    private static function lightFirst(string $dir): bool
    {
        if (self::$lockFn !== null) {
            return false; // unit tests with a flock double: the probe would count the double's own locks
        }
        $cores = (int)(ScaleTier::settings()['cores'] ?? 0);
        $need = $cores >= 1 ? max(2, $cores) : 4;
        $n = (int)(ScaleTier::get()['slots']['light'] ?? 1);
        if ($n < $need) {
            return false;
        }
        $busy = 0;
        for ($i = 0; $i < $n; $i++) {
            if (!is_file($dir . 'light-' . $i)) {
                continue;
            }
            $h = @fopen($dir . 'light-' . $i, 'c');
            if (!$h) {
                continue;
            }
            $r = self::lock($h, 0);
            if ($r === true) {
                @flock($h, LOCK_UN);
            }
            fclose($h);
            if ($r === null) {
                return false;
            }
            if ($r === false && ++$busy >= $need) {
                return true;
            }
        }
        return false;
    }

    /** Try lock files <prefix>0..n-1: true (held, pushed to $held), false (all busy), null (flock unsupported: fail open). */
    private static function tryAny(string $prefix, int $n, int $from = 0): ?bool
    {
        $i = self::tryIndex($prefix, $n, $from);
        return $i >= 0 ? true : ($i === -1 ? false : null);
    }

    /** tryAny() returning the index of the lock taken (pushed to $held), -1 (all busy) or -2 (flock unsupported: fail open). */
    private static function tryIndex(string $prefix, int $n, int $from = 0): int
    {
        for ($i = max(0, $from); $i < $n; $i++) {
            $h = @fopen($prefix . $i, 'c');
            if (!$h) {
                continue;
            }
            $r = self::lock($h, 0);
            if ($r === true) {
                self::$held[] = $h;
                return $i;
            }
            fclose($h);
            if ($r === null) {
                self::flockUnsupported();
                return -2;
            }
        }
        return -1;
    }

    /**
     * One in-flight mark of the key: '' = taken (or flock unsupported, or no place file could be opened: see takePlace()),
     * 'inflight' = the key already has INFLIGHT[$kind] x $lift renders in flight, 'full' (flood-r4 O-F1c) = a light page beyond
     * INFLIGHT_LIGHT_FULL x $lift while every light render token is taken (render-cap mode). $lift: UA_LIFT for a peer the
     * User-Agent heuristic flagged, else 1. FR-3 (decision O-29): the places are the key's own files (placeName()), so only
     * the key's own renders can refuse it; a render of another visitor never does.
     */
    private static function takeInflight(string $kind, string $key, int $lift = 1): string
    {
        $dir = self::dir() . 'k/';
        $place = self::placeName($key);
        $sub = $dir . substr($place, 0, 2) . '/';
        if (!is_dir($sub)) { @mkdir($sub, 0755, true); }
        $lift = max(1, min(self::UA_LIFT, $lift));
        $prefix = $dir . $place . '-' . $kind[0] . '-';
        $i = self::takePlace($prefix, self::INFLIGHT[$kind] * $lift);
        if ($i === -1) {
            return 'inflight';
        }
        if ($i >= 0 && $kind === 'heavy') {
            // flood-r7 R7-2: the place file's mtime = when a render took it (before its class slot; set back by untouchHeavy()
            // when the slot is refused), so the fast shed of preCheck() probes this key's place only while a render of it can be
            // in flight (heavyCandidate())
            self::$heavyTouched = $prefix . $i;
            @touch(self::$heavyTouched);
        }
        if ($i >= self::INFLIGHT_LIGHT_FULL * $lift && $kind === 'light' && self::lightTokensFull()) {
            self::release(array_pop(self::$held));
            return 'full';
        }
        return '';
    }

    /**
     * flood-r6 O-4: true when this request's key (per-key budgets on, not a verified crawler) already holds every in-flight
     * place of $kind ('heavy' | 'light'; x its UA lift) in other requests right now, i.e. enter() would refuse it as 'inflight'
     * (non-blocking probe of the key's lock files, nothing is kept). Lets the fast shed of preCheck() tell a class filled by
     * the key's own render (not load) from a full machine. A missing lock file, flock unsupported or any error -> false.
     */
    private static function ownCapFull(string $kind): bool
    {
        try {
            $who = self::who();
            if ($who['key'] === '' || !$who['budgets'] || ($who['crawler'] ?? '') !== '') {
                return false;
            }
            $lift = max(1, min(self::UA_LIFT, (int)($who['lift'] ?? 1)));
            $place = self::placeName($who['key']);
            $names = [];
            for ($i = 0; $i < self::INFLIGHT[$kind] * $lift; $i++) {
                $names[] = $place . '-' . $kind[0] . '-' . $i;
            }
            return self::allHeld(self::dir() . 'k/', $names);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * FR-3 (decision O-29): the in-flight place of a key, relative to k/: '<2 hex>/<PLACE_HEX hex of sha1(key)>' (the files
     * <place>-h-<i> / <place>-l-<i> are the key's own; 256 folders keep each one small for sweep()).
     */
    private static function placeName(string $key): string
    {
        $h = substr(sha1($key), 0, self::PLACE_HEX);
        return substr($h, 0, 2) . '/' . $h;
    }

    /**
     * FR-3: one of the key's in-flight places <prefix>0..n-1: the index taken (the lock is kept in $held), -1 when every place is
     * held by another request of the key (the key's own renders: its places are no one else's), -2 when flock is unsupported (fail
     * open with the notice, as tryIndex()), -3 when no place file could be opened and none was found held (no rights, or a file
     * sweep() removed a moment ago on a file system without POSIX delete): no render of the key is known to be in flight, so the
     * render goes on without a mark (fail open, like a busy budget shard).
     */
    private static function takePlace(string $prefix, int $n): int
    {
        $busy = 0;
        for ($i = 0; $i < $n; $i++) {
            $h = @fopen($prefix . $i, 'c');
            if (!$h) {
                continue;
            }
            $r = self::lock($h, 0);
            if ($r === true) {
                self::$held[] = $h;
                return $i;
            }
            fclose($h);
            if ($r === null) {
                self::flockUnsupported();
                return -2;
            }
            $busy++;
        }
        return $busy > 0 ? -1 : -3;
    }

    /** FR-3: seconds after which sweep() removes a place file (SWEEP_AGE, at least heavyHold() + slack). */
    private static function sweepAge(): int
    {
        return max(self::SWEEP_AGE, self::heavyHold() + self::HEAVY_HOLD_SLACK);
    }

    /**
     * FR-3 (decision O-29): housekeeping of the per-key in-flight places (Housekeeping::scale(), task 'gate'). Walks the place
     * folders k/00 .. k/ff and then k/ itself (the bucket-named files of the code before FR-3, k/<3 hex>-h|l-<i>), resuming where
     * the last call stopped (k/sweep.json: folder + last name done), until $deadline or $maxFiles place names were looked at. A
     * place file is removed when its mtime is older than sweepAge() or lies more than SWEEP_AGE in the future. The sweep never takes
     * a place's lock (a render of the key can never find its place busy because of the sweep, so it never causes an in-flight
     * refusal): a heavy place older than sweepAge() belongs to no render (takeInflight() touches it); a light place keeps its creation
     * time, and when a render still holds one that is removed, the render keeps its lock on the unnamed file and the key may start one
     * more render at that moment, never one fewer. Only plain files with a place name, never a link, never through a linked folder.
     * Returns ['state' => 'done' | 'partial' | 'empty' | 'error', 'folders' => n, 'seen' => n, 'removed' => n, 'kept' => n,
     * 'next' => folder]; never throws.
     */
    public static function sweep(float $deadline, int $maxFiles = 20000): array
    {
        $out = ['state' => 'done', 'folders' => 0, 'seen' => 0, 'removed' => 0, 'kept' => 0, 'next' => 0];
        try {
            $k = self::dir() . 'k/';
            if (!is_dir($k) || is_link(rtrim($k, '/'))) {
                $out['state'] = 'empty';
                return $out;
            }
            $now = self::$now ?? time();
            $before = $now - self::sweepAge();
            $budget = max(1, $maxFiles);
            $cursorFile = $k . 'sweep.json';
            $cur = ScaleTier::readJson($cursorFile);
            $next = is_array($cur) ? max(0, min(256, (int)($cur['next'] ?? 0))) : 0;
            $after = is_array($cur) && is_string($cur['after'] ?? null) ? (string)$cur['after'] : '';
            $place = '~^[0-9a-f]{' . self::PLACE_HEX . '}-[hl]-\d{1,2}$~';
            for ($visited = 0; $visited < 257; $visited++) {
                if ($budget <= 0 || microtime(true) >= $deadline) {
                    $out['state'] = 'partial';
                    break;
                }
                $dir = $next === 256 ? $k : $k . sprintf('%02x', $next) . '/';
                if ($next === 256 || (is_dir($dir) && !is_link(rtrim($dir, '/')))) {
                    $last = '';
                    $complete = self::sweepFolder($dir, $next === 256 ? '~^[0-9a-f]{3}-[hl]-\d{1,2}$~' : $place, $before, $now, $after, $out, $budget, $deadline, $last);
                    if (!$complete) {
                        $after = $last !== '' ? $last : $after;
                        $out['state'] = 'partial';
                        break;
                    }
                }
                $after = '';
                $out['folders']++;
                $next = ($next + 1) % 257;
            }
            $out['next'] = $next;
            ScaleTier::writeAtomic($cursorFile, json_encode(['next' => $next, 'after' => $after, 'at' => $now]));
        } catch (\Throwable $e) {
            $out['state'] = 'error';
        }
        return $out;
    }

    /**
     * sweep(): one folder, names in byte order after $after; false when it stopped on the budget or the deadline ($last = the last
     * name done, where the next call goes on).
     */
    private static function sweepFolder(string $dir, string $pattern, int $before, int $now, string $after, array &$out, int &$budget, float $deadline, string &$last): bool
    {
        $names = @scandir($dir, SCANDIR_SORT_NONE);
        if (!is_array($names)) {
            return true;
        }
        sort($names, SORT_STRING);
        foreach ($names as $name) {
            if (($after !== '' && strcmp($name, $after) <= 0) || !preg_match($pattern, $name)) {
                continue;
            }
            if ($budget <= 0 || microtime(true) >= $deadline) {
                return false;
            }
            $budget--;
            $last = $name;
            $out['seen']++;
            $p = $dir . $name;
            clearstatcache(true, $p);
            if (is_link($p) || !is_file($p)) {
                continue;
            }
            $m = @filemtime($p);
            if ($m === false || ($m >= $before && $m <= $now + self::SWEEP_AGE)) {
                $out['kept']++;                 // taken or created lately: kept
                continue;
            }
            if (@unlink($p)) {
                $out['removed']++;
            }
        }
        return true;
    }

    /** flood-r7 R7-2: the heavy place this request took will not render (no class slot): its file gets an old mtime again. */
    private static function untouchHeavy(): void
    {
        if (self::$heavyTouched !== null) {
            @touch(self::$heavyTouched, 1);
            self::$heavyTouched = null;
        }
    }

    /** flood-r7 R7-2: seconds a heavy render holds its in-flight place at most (see HEAVY_HOLD_SLACK). */
    private static function heavyHold(): int
    {
        try {
            $limit = (int)(ScaleTier::get()['stmt_limit'] ?? 0);
        } catch (\Throwable $e) {
            $limit = 0;
        }
        return $limit > 0 ? min(self::HEAVY_HOLD_MAX, $limit + self::HEAVY_HOLD_SLACK) : self::HEAVY_HOLD_MAX;
    }

    /**
     * flood-r7 R7-2: the decision of preCheck()'s fast shed once allBusy() saw the heavy class $class full. 'own' = this request's
     * key holds all its heavy in-flight places (ownCapFull(): its own cap, not load), 'load' = the class is full of other
     * renders, 'busy' = the site sheds already and the key has a render in flight (own cap or load: the same answer, the stale
     * copy or the busy page; no who(), no shed mark: other load refusals keep it fresh), '' = the class freed meanwhile (a render
     * of this key just ended): the normal path decides.
     * The client key (who(): trusted-proxy rules, Cloudflare ranges, User-Agent flags; about 0.4 ms, flood-r7 measured -19 to
     * -21 % shed answers per second with it on every refusal) is asked only when heavyCandidate() finds one of the request's
     * addresses with its first heavy place held; the class is probed again only when such an address had a heavy render within
     * heavyHold() (it may have ended after the first probe: flood-r6 O-4's race). Without any, the refusal is load at once.
     */
    private static function fastShed(string $class): string
    {
        $cand = self::heavyCandidate();
        if ($cand === 'held') {
            if (self::shedding()) {
                return 'busy';
            }
            if (self::ownCapFull('heavy')) {
                return 'own';
            }
        }
        if ($cand !== '' && !self::allBusy($class)) {
            return '';
        }
        return 'load';
    }

    /**
     * flood-r7 R7-2: whether this request's key may hold a heavy in-flight place, without who(): the key is '4:<address>' or
     * '6:<64 bits>' of one of the addresses PublicRequestGuard::clientIp() picks from (REMOTE_ADDR, CF-Connecting-IP, X-Real-IP,
     * the X-Forwarded-For entries), so each such address's first heavy place file (k/<placeName(key)>-h-0, the key's own file
     * since FR-3; a key holding all its places holds place 0) is looked at: 'held' = one is held by another request right now
     * (non-blocking probe), 'recent' = one was taken by a render within heavyHold() but is free now, '' = none was taken within
     * heavyHold() (no render of this key can be in flight or have just ended). One stat per address, a probe only for a recently
     * taken place. More than OWN_ADDR_MAX addresses or an error: 'held' (ownCapFull() decides, as before).
     */
    private static function heavyCandidate(): string
    {
        try {
            $s = $_SERVER;
            $raw = [];
            foreach (['REMOTE_ADDR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP'] as $h) {
                if (isset($s[$h]) && is_string($s[$h]) && $s[$h] !== '') {
                    $raw[] = $s[$h];
                }
            }
            $xff = $s['HTTP_X_FORWARDED_FOR'] ?? '';
            if (is_string($xff) && $xff !== '') {
                if (strlen($xff) > 2048) {
                    return 'held';
                }
                foreach (explode(',', $xff) as $a) {
                    $raw[] = trim($a);
                }
            }
            if (count($raw) > self::OWN_ADDR_MAX) {
                return 'held';
            }
            $places = [];
            foreach ($raw as $a) {
                if (strlen($a) > 45 || filter_var($a, FILTER_VALIDATE_IP) === false) {
                    continue;
                }
                $p = @inet_pton($a);
                if ($p === false) {
                    continue;
                }
                if (strlen($p) === 16 && substr($p, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
                    $p = substr($p, 12);
                }
                $key = strlen($p) === 4 ? '4:' . inet_ntop($p) : '6:' . bin2hex(substr($p, 0, 8));
                $places[self::placeName($key)] = true;
            }
            $dir = self::dir() . 'k/';
            $since = (self::$now ?? time()) - self::heavyHold();
            $out = '';
            foreach (array_keys($places) as $b) {
                $f = $dir . $b . '-h-0';
                $m = @filemtime($f);
                if ($m === false || $m < $since) {
                    continue;
                }
                $h = @fopen($f, 'c');
                if (!$h) {
                    return 'held';
                }
                $r = self::lock($h, 0);
                if ($r === true) {
                    @flock($h, LOCK_UN);
                }
                fclose($h);
                if ($r !== true) {
                    return 'held';      // held by another request (or flock unsupported: ownCapFull() decides)
                }
                $out = 'recent';
            }
            return $out;
        } catch (\Throwable $e) {
            return 'held';
        }
    }

    /**
     * flood-r4 O-F1c: render-cap mode and every light render token (render-l-*) is held by other requests right now
     * (non-blocking probe, a free token is released at once). false without render-cap mode, without light tokens or when a
     * token file is missing (the normal path decides).
     */
    private static function lightTokensFull(): bool
    {
        $g = self::gateCfg();
        $render = max(0, (int)($g['render'] ?? 0));
        $reserve = max(0, min($render - 1, (int)($g['reserve'] ?? 0)));
        if ($render <= 0 || $reserve <= 0) {
            return false;
        }
        $names = [];
        for ($i = 0; $i < $reserve; $i++) {
            $names[] = 'render-l-' . $i;
        }
        return self::allHeld(self::dir() . 's/', $names);
    }

    /** flock through the test double when set; true / false (busy) / null (unsupported). */
    private static function lock($h, int $waitMs): ?bool
    {
        if (self::$lockFn !== null) {
            $wb = 0;
            $ok = (self::$lockFn)($h, LOCK_EX | LOCK_NB, $wb);
            if ($ok) { return true; }
            return $wb ? false : null;
        }
        return ScaleTier::lock($h, LOCK_EX, $waitMs);
    }

    private static function flockUnsupported(): void
    {
        $f = self::dir() . 'flock-unsupported';
        if (!is_file($f)) {
            @file_put_contents($f, (string)time());
        }
    }

    // ----- proxy notice, UA heuristic, statistics -----

    /** Notes a pooled proxy peer with a client-address header, and feeds the User-Agent heuristic (public peers). */
    private static function observe(array $who): void
    {
        $now = self::$now ?? time();
        if ($who['via'] === 'pooled' && $who['header'] !== '') {
            $f = self::dir() . 'proxy-notice.json';
            if (!is_file($f) || $now - (int)filemtime($f) > 300) {
                ScaleTier::writeAtomic($f, json_encode(['peer' => $who['peer'], 'header' => $who['header'], 'at' => $now]));
            }
            return;
        }
        if (!in_array($who['via'], ['direct', 'proxy'], true) || $who['ip'] === '' || ($who['crawler'] ?? '') !== '') {
            return;
        }
        // sampled: one miss in UA_SAMPLE updates the statistics with weight UA_SAMPLE (keeps the pre-boot path short)
        if (self::$now === null && mt_rand(1, self::UA_SAMPLE) !== 1) {
            return;
        }
        $weight = self::$now === null ? self::UA_SAMPLE : 1;
        $file = self::dir() . 'ua-' . intdiv($now, self::UA_WINDOW) . '.json';
        $h = @fopen($file, 'c+');
        if (!$h) {
            return;
        }
        if (self::lock($h, 0) === true) {
            $st = json_decode((string)stream_get_contents($h), true);
            if (!is_array($st) || !isset($st['n'], $st['p'])) { $st = ['n' => 0, 'p' => []]; }
            $st['n'] += $weight;
            $peer = $who['ip'];
            if (!isset($st['p'][$peer]) && count($st['p']) >= 64) {
                // keep the 32 busiest peers only
                uasort($st['p'], function ($a, $b) { return $b['c'] <=> $a['c']; });
                $st['p'] = array_slice($st['p'], 0, 32, true);
            }
            $e = $st['p'][$peer] ?? ['c' => 0, 'u' => []];
            $e['c'] += $weight;
            $ua = substr(md5((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 8);
            if (count($e['u']) <= self::UA_DISTINCT * 2 && !in_array($ua, $e['u'], true)) {
                $e['u'][] = $ua;
            }
            $st['p'][$peer] = $e;
            if ($st['n'] >= self::UA_MIN_MISSES && $e['c'] * 2 > $st['n'] && count($e['u']) > self::UA_DISTINCT) {
                self::flagPeer($peer, $now);
            }
            ftruncate($h, 0);
            rewind($h);
            fwrite($h, json_encode($st));
            fflush($h);
            @flock($h, LOCK_UN);
        }
        fclose($h);
    }

    /**
     * The User-Agent heuristic flagged $peer (synth decision 15): runtime/gate/proxy-peers.json keeps peer => end of its
     * in-flight lift (now + UA_LIFT_SECONDS; a peer that keeps qualifying is flagged again in each window, so the lift
     * follows it; entries of older code held the flag time and have ended); the proxy notice names the peer. The per-key
     * budget is never lifted.
     */
    private static function flagPeer(string $peer, int $now): void
    {
        $f = self::dir() . 'proxy-peers.json';
        $list = ScaleTier::readJson($f) ?: [];
        if ((int)($list[$peer] ?? 0) > $now + self::UA_LIFT_SECONDS - 60) {
            return;   // flagged less than a minute ago: no rewrite for every sampled miss of a busy peer
        }
        $list[$peer] = $now + self::UA_LIFT_SECONDS;
        foreach ($list as $p => $t) {
            if ((int)$t <= $now) { unset($list[$p]); }
        }
        if (count($list) > 256) {
            arsort($list);
            $list = array_slice($list, 0, 256, true);
        }
        ScaleTier::writeAtomic($f, json_encode($list));
        ScaleTier::writeAtomic(self::dir() . 'proxy-notice.json', json_encode(['peer' => $peer, 'header' => 'user-agents', 'at' => $now]));
        self::$peers = null;
    }

    /** $peer has an active in-flight lift of the User-Agent heuristic (flagPeer(): its end time has not passed). */
    private static function flaggedPeer(string $peer): bool
    {
        if (self::$peers === null) {
            self::$peers = ScaleTier::readJson(self::dir() . 'proxy-peers.json') ?: [];
        }
        return isset(self::$peers[$peer]) && (int)self::$peers[$peer] > (self::$now ?? time());
    }

    private static function logDenied(string $key, int $now): void
    {
        $f = self::dir() . 'denied-' . date('YmdH', $now) . '.log';
        if (!is_file($f) || filesize($f) < 1048576) {
            @file_put_contents($f, $key . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    /** Notices for the Speed page and tools/upgrade.php: proxy (peer + header), flock unsupported, 429s per hour, top 5 keys. */
    public static function notices(): array
    {
        $dir = self::dir();
        $proxy = ScaleTier::readJson($dir . 'proxy-notice.json');
        $deniedFile = $dir . 'denied-' . date('YmdH') . '.log';
        $lines = is_file($deniedFile) ? (file($deniedFile, FILE_IGNORE_NEW_LINES) ?: []) : [];
        $top = array_count_values($lines);
        arsort($top);
        return ['proxy' => is_array($proxy) && (time() - (int)($proxy['at'] ?? 0)) < 86400 ? $proxy : null,
            'flock_unsupported' => is_file($dir . 'flock-unsupported'), 'denied_this_hour' => count($lines),
            'top_keys' => array_slice($top, 0, 5, true)];
    }

    /** Shape asked by lane E (HO-E-6): r429_hour, top_keys [{key, n}], flock_ok, proxy_notice {peer, headers[]} | null. */
    public static function stats(): array
    {
        $n = self::notices();
        $top = [];
        foreach ($n['top_keys'] as $k => $c) { $top[] = ['key' => (string)$k, 'n' => (int)$c]; }
        return ['r429_hour' => $n['denied_this_hour'], 'top_keys' => $top, 'flock_ok' => !$n['flock_unsupported'],
            'proxy_notice' => $n['proxy'] ? ['peer' => (string)$n['proxy']['peer'], 'headers' => [(string)$n['proxy']['header']]] : null];
    }

    /** flock self-test on the gate directory (tools/upgrade.php, Speed page): 'ok' | 'unsupported' | 'error'. */
    public static function selfTest(): string
    {
        $f = self::dir() . 's/selftest.lock';
        if (!is_dir(dirname($f))) { @mkdir(dirname($f), 0755, true); }
        $a = @fopen($f, 'c');
        $b = @fopen($f, 'c');
        if (!$a || !$b) {
            return 'error';
        }
        $r1 = self::lock($a, 0);
        $r2 = self::lock($b, 0);
        @flock($a, LOCK_UN);
        @flock($b, LOCK_UN);
        fclose($a);
        fclose($b);
        if ($r1 === null || $r2 === null) {
            self::flockUnsupported();
            return 'unsupported';
        }
        return ($r1 === true && $r2 === false) ? 'ok' : 'unsupported';
    }

    /**
     * runtime/gate/ of the site (created when missing). flood-r7 R7-2: remembered for the request (per site root), so a shed
     * answer stats the folder once instead of once per gate step (about 20 calls on the slow path).
     */
    private static function dir(): string
    {
        $root = ScaleTier::root();
        if (self::$dirMemo === null || self::$dirMemo[0] !== $root) {
            self::$dirMemo = [$root, ScaleTier::runtime('gate')];
        }
        return self::$dirMemo[1];
    }

    /** Test hook: forget per-request state. */
    public static function reset(): void
    {
        self::leave();
        foreach (self::$otherRequests as $hs) {
            foreach ($hs as $h) { @flock($h, LOCK_UN); @fclose($h); }
        }
        self::$otherRequests = [];
        self::$consumed = [];
        self::$charged = [];
        self::$who = null;
        self::$peers = null;
        self::$shed = ['why' => '', 'class' => ''];
        self::$gateMemo = null;
        self::$shedMemo = null;
        self::$dirMemo = null;
        self::$heavyTouched = null;
    }
}
