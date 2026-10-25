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
namespace app\common\util\updater;

/**
 * Finds the newest release and fetches signed manifests (UPDATER decisions U1, U2; UPDATER-SCOUT-HOST rules 9, 10, 18).
 *
 * Order of a check:
 * 1. GitHub API  GET /repos/avdbapi-com/javcms/releases/latest  (User-Agent, Accept: application/vnd.github+json,
 *    X-GitHub-Api-Version, If-None-Match). Unauthenticated callers get 60 requests per hour per IP, shared with every
 *    site on that IP, and a 304 still counts: automatic checks run at most every 12 hours, a manual one at most every
 *    minute; only an answer that says no requests are left (or asks to retry later) stops the API until GitHub's reset.
 * 2. Without the API: https://github.com/<repo>/releases/latest/download/javcms-release.json (+ .sig), the stable-named
 *    copy of the newest manifest that every release carries. No API quota.
 * 3. Offline: a manifest + signature (and the zip) that the owner uploaded into runtime/update/inbox/ (hosts without
 *    outgoing HTTPS).
 * GitHub is the only network source (amendment A1 of 2026-10-23): when it cannot be reached the
 * check fails with a clear message and nothing changes.
 * One check never takes longer than about 20 seconds in all (CHECK_BUDGET, less when PHP's time limit is shorter): a
 * firewall that swallows outgoing HTTPS gives a clear answer instead of a request that a gateway cuts off.
 * Whatever the way, the manifest must carry a valid signature of a trusted key, name product "javcms" and be newer than
 * the installed version. A verified manifest is stored (runtime/update/manifests/); the newest one ever seen keeps being
 * offered when GitHub later answers with an older release (a stale cache, a replayed answer).
 * When the newest release cannot be installed from this version (its upgrade.min_from is newer), the newest release
 * that can is offered instead (one API listing of the releases): the site gets there in two updates, never stuck.
 * The same listing helps a site that missed a key rotation: when the newest release is signed by a key this site does
 * not know, the releases before it are verified newest first; the first that verifies (it carries the signed rotation
 * statement, which the site then remembers) unlocks the newest one, or is offered first.
 * When GitHub cannot be reached (a host whose firewall drops outgoing HTTPS), that is remembered for a while: the
 * Review then looks for the installed version's manifest in the stored manifests and the inbox only, instead of
 * waiting for GitHub in every slice, and a failed fetch of a manifest is not repeated for MISS_SECONDS.
 */
final class Source
{
    /** Seconds one check may take in all. */
    const CHECK_BUDGET = 20.0;
    /** A check that could not reach GitHub at all makes the following Review work offline for this long. */
    const OFFLINE_SECONDS = 1800;
    /** A failed fetch of one version's manifest is not tried again for this long (every Review slice would wait again). */
    const MISS_SECONDS = 600;

    /** Asset names of a release. */
    public static function manifestName($version)
    {
        return 'javcms-' . $version . '.json';
    }

    public static function zipName($version)
    {
        return 'javcms-' . $version . '.zip';
    }

    /** Folder of verified manifests. */
    private static function store()
    {
        return Util::dir('manifests');
    }

    /** Cached result of the last check (runtime/update/check.php). */
    public static function cached()
    {
        $c = Util::loadData(Util::root() . 'runtime/update/check.php');
        return is_array($c) ? $c : array();
    }

    private static function saveCache(array $c)
    {
        $dir = Util::dir();
        if ($dir !== '') {
            Util::saveData($dir . 'check.php', $c);
        }
    }

    /** End time of a check of at most $want seconds, never past PHP's own limit for this request (minus a margin). */
    public static function deadline($want = self::CHECK_BUDGET)
    {
        $want = (float)$want;
        $limit = (int)@ini_get('max_execution_time');
        if ($limit > 0 && PHP_SAPI !== 'cli') {
            $want = min($want, max(3.0, $limit - 5.0));
        }
        return microtime(true) + max(3.0, $want);
    }

    private static function left($deadline, $cap)
    {
        return max(0.0, min((float)$cap, $deadline - microtime(true)));
    }

    /**
     * Checks for the newest release. $mode 'auto' (respects the 12-hour interval and a known rate-limit block),
     * 'manual' (at most every minute), 'force' (tests, CLI --check). Returns the cache record:
     * ['checked_at', 'state' => 'available'|'current'|'error'|'skipped', 'latest' => summary|null, 'notes', 'release_url',
     *  'via', 'error' (technical details), 'error_k' (message key of the page), 'note', 'note_k', 'note_v', 'installed',
     *  'rate' => ['remaining', 'reset', 'blocked_until']].
     */
    public static function check($mode = 'manual', $deadline = null)
    {
        $c = self::cached();
        $now = time();
        $installed = Config::installedVersion();
        if ($mode !== 'force') {
            $last = (int)($c['requested_at'] ?? 0);
            $gap = $mode === 'auto' ? Config::CHECK_EVERY : Config::CHECK_MIN_GAP;
            if ($last > 0 && $now - $last < $gap && ($c['installed'] ?? '') === $installed) {
                return self::present($c + array('state' => 'skipped'));
            }
        }
        $lock = @fopen((Util::dir() ?: sys_get_temp_dir() . '/') . 'check.lock', 'c');
        if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            if (!Store::locksWork()) {
                // not another check: a file system without file locks (some NFS / FUSE mounts), where every lock fails -
                // "skipped" would be the answer for ever; the updater cannot run safely there and says so
                return self::present(array('state' => 'error', 'error' => 'runtime/ is on a file system without file locks', 'error_k' => 'req_locks',
                    'error_v' => array()) + $c);
            }
            return self::present($c + array('state' => 'skipped'));
        }
        $deadline = $deadline === null ? self::deadline() : (float)$deadline;
        $t0 = microtime(true);
        $c['requested_at'] = $now;
        $c['installed'] = $installed;
        unset($c['key_step']);
        self::saveCache($c);
        $errors = array();
        $kinds = array();
        $found = null;
        $via = '';
        $release = null;
        // 1. API (skipped only while GitHub said that no requests are left)
        $blocked = (int)($c['rate']['blocked_until'] ?? 0);
        if ($blocked <= $now || $mode === 'force') {
            $api = self::viaApi($c, $errors, $kinds, $deadline);
            if ($api !== null) {
                list($found, $release) = $api;
                $via = 'api';
            }
        } else {
            $errors[] = 'GitHub API limit reached until ' . gmdate('H:i', $blocked) . ' UTC (the API was not asked)';
            $kinds[] = 'limit';
        }
        // 2. GitHub without the API
        if ($found === null) {
            $cfg = Config::endpoints();
            $ways = array(
                'web' => $cfg['web'] . '/' . $cfg['repo'] . '/releases/latest/download/javcms-release.json',
            );
            foreach ($ways as $name => $url) {
                if (rtrim($url, '/') === '/javcms-release.json') {
                    continue;
                }
                if (self::left($deadline, 30) < 2.0) {
                    $errors[] = $name . ': not asked (no time left)';
                    $kinds[] = 'time';
                    continue;
                }
                $kind = '';
                $m = self::fetchPair($url, $url . '.sig', $err, '', $deadline, $kind);
                if ($m !== null) {
                    $found = $m;
                    $via = $name;
                    break;
                }
                $errors[] = $name . ': ' . $err;
                $kinds[] = $kind;
            }
        }
        unset($c['gh_ok_at']);
        // GitHub reached at all (any answer, even an error status)? A check where nothing answered makes the Review work
        // offline for a while (installedManifest(): stored manifests and the inbox only)
        $reached = $found !== null;
        foreach ($kinds as $k) {
            if (!in_array($k, array('net', 'time', 'limit'), true)) {
                $reached = true;
            }
        }
        if ($reached) {
            unset($c['gh_unreachable_at']);
            // GitHub answers again: a manifest fetch that failed a few minutes ago is tried again at the next need
            if (is_file(Util::root() . 'runtime/update/misses.php')) {
                @unlink(Util::root() . 'runtime/update/misses.php');
            }
        } elseif ($kinds) {
            $c['gh_unreachable_at'] = $now;
        }
        // 3. offline inbox
        $inbox = self::fromInbox();
        if ($inbox !== null && ($found === null || Util::semverCompare($inbox->version(), $found->version()) > 0)) {
            $found = $inbox;
            $via = 'inbox';
        }
        // the newest manifest ever verified stays on offer when a source answers with an older one
        $best = self::bestStored();
        $note = '';
        $noteK = '';
        $noteV = array();
        if ($best !== null && ($found === null || Util::semverCompare($best->version(), $found->version()) > 0)
            && Util::semverCompare($best->version(), $installed) > 0) {
            if ($found !== null) {
                $note = 'the source answered with ' . $found->version() . ', older than ' . $best->version() . ' seen before; ' . $best->version() . ' stays on offer';
                $noteK = 'note_stale';
                $noteV = array('got' => $found->version(), 'best' => $best->version());
            }
            $found = $best;
            if ($via === '') {
                $via = 'stored';
                // nothing answered with a release just now: the offer is the one found before, and it says so (the
                // download needs GitHub or the inbox)
                $seen = Util::loadData(Util::root() . 'runtime/update/seen.php');
                $date = is_array($seen) && ($seen['version'] ?? '') === $best->version() && !empty($seen['at'])
                    ? date('Y-m-d H:i', (int)$seen['at']) : (string)($best->summary()['released'] ?? '');
                $note = 'GitHub did not answer just now; ' . $best->version() . ' is the release found on ' . $date . '. The download needs GitHub or the inbox.';
                $noteK = 'note_unreached';
                $noteV = array('v' => $best->version(), 'date' => $date);
            }
        }
        // the newest release is signed with a key this site does not know yet: an older one that it can verify comes first
        if ($noteK === '' && $found !== null && !empty($c['key_step']) && $found->version() === (string)($c['key_step']['v'] ?? '')) {
            $nv = (string)$c['key_step']['newest'];
            $note = 'JavCMS ' . $nv . ' is signed with a new release key this site does not know yet: ' . $found->version()
                . ' (signed with a key it knows) is offered first, ' . $nv . ' after it';
            $noteK = 'note_key_step';
            $noteV = array('newest' => $nv, 'v' => $found->version());
        }
        // a newest release this version cannot update to straight away: the newest one that it can
        if ($found !== null && Util::semverValid($installed) && Util::semverCompare($found->version(), $installed) > 0) {
            $min = $found->upgrade()['min_from'];
            if ($min !== '' && Util::semverCompare($installed, $min) < 0) {
                $stone = self::steppingStone($c, $installed, $found->version(), $deadline);
                if ($stone !== null) {
                    $note = 'JavCMS ' . $found->version() . ' needs ' . $min . ' or newer first: ' . $stone->version() . ' is offered now, ' . $found->version() . ' after it';
                    $noteK = 'note_stepping';
                    $noteV = array('newest' => $found->version(), 'min' => $min, 'v' => $stone->version());
                    $c['newest'] = $found->version();
                    $found = $stone;
                    $release = null;
                }
            }
        }
        $c['checked_at'] = $now;
        $c['via'] = $via;
        $c['error'] = $found === null ? implode('; ', array_slice($errors, 0, 4)) : '';
        $c['error_k'] = $found === null ? self::errorKey($kinds, $deadline, $t0) : '';
        $c['error_v'] = $found === null ? array('s' => (int)round(microtime(true) - $t0)) : array();
        $c['note'] = $note;
        $c['note_k'] = $noteK;
        $c['note_v'] = $noteV;
        if ($found === null) {
            $c['state'] = 'error';
        } else {
            $sum = $found->summary();
            $c['latest'] = $sum;
            $c['notes'] = Util::cut($found->notes(), 60000);
            $c['release_url'] = self::releaseUrl($release['html_url'] ?? '', $sum['tag']);
            if ($found->notes() === '' && !empty($release['body'])) {
                $c['notes'] = Util::cut((string)$release['body'], 60000);
            }
            $cmp = $installed !== '' && Util::semverValid($installed) ? Util::semverCompare($found->version(), $installed) : 1;
            $c['state'] = $cmp > 0 ? 'available' : 'current';
            if ($noteK !== 'note_stepping') {
                unset($c['newest']);
            }
            self::rememberSeen($found->version());
        }
        self::saveCache($c);
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return self::present($c);
    }

    /**
     * Message key of a failed check: nothing answered (offline / firewall), GitHub answered only with server errors (it
     * is down for a while), a refused signature, or something else. Every one of them changes nothing on the site.
     */
    private static function errorKey(array $kinds, $deadline, $t0)
    {
        $kinds = array_values(array_filter($kinds, function ($k) {
            return $k !== 'limit';
        }));
        $count = function (array $which) use ($kinds) {
            return count(array_filter($kinds, function ($k) use ($which) {
                return in_array($k, $which, true);
            }));
        };
        if ($kinds && $count(array('net', 'time')) === count($kinds)) {
            return microtime(true) >= $deadline - 0.5 ? 'ce_timeout' : 'ce_unreachable';
        }
        if (in_array('signature', $kinds, true) || in_array('unknown_key', $kinds, true)) {
            return 'ce_signature';
        }
        if ($kinds && $count(array('down')) > 0 && $count(array('net', 'time', 'down')) === count($kinds)) {
            return 'ce_github_down';
        }
        return 'ce_failed';
    }

    /** Sort of a failed request: 'net' (no answer at all), 'down' (a server error of GitHub, 5xx), else $other. */
    private static function failKind($status, $other)
    {
        $status = (int)$status;
        if ($status === 0) {
            return 'net';
        }
        return $status >= 500 && $status <= 599 ? 'down' : $other;
    }

    /**
     * Address of the release page for the "Release page on GitHub" link: the API's html_url only when it is a release
     * page of this repository (the link is put into the admin page), else built from the tag.
     */
    public static function releaseUrl($url, $tag)
    {
        $cfg = Config::endpoints();
        $prefix = $cfg['web'] . '/' . $cfg['repo'] . '/releases/';
        $url = (string)$url;
        if ($url !== '' && strpos($url, $prefix) === 0 && preg_match('#^https?://[^\s"\'<>`]+$#', $url)
            && (strpos($url, 'https://') === 0 || $cfg['test'])) {
            return $url;
        }
        return $prefix . 'tag/' . rawurlencode((string)$tag);
    }

    /** The cache record with derived fields for the page. */
    private static function present(array $c)
    {
        $c += array('state' => 'never', 'latest' => null, 'notes' => '', 'via' => '', 'error' => '', 'error_k' => '', 'checked_at' => 0, 'note' => '',
            'note_k' => '', 'note_v' => array(), 'release_url' => '');
        $c['installed'] = Config::installedVersion();
        if (is_array($c['latest']) && Util::semverValid($c['installed'])) {
            $c['available'] = Util::semverCompare((string)$c['latest']['version'], $c['installed']) > 0;
        } else {
            $c['available'] = false;
        }
        return $c;
    }

    /** API step; returns [Manifest, release] or null (errors appended). Updates $c (etag, rate, release cache). */
    private static function viaApi(array &$c, array &$errors, array &$kinds, $deadline)
    {
        $cfg = Config::endpoints();
        if ($cfg['api'] === '') {
            return null;
        }
        $url = $cfg['api'] . '/repos/' . $cfg['repo'] . '/releases/latest';
        $h = array('Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28');
        if (!empty($c['etag']) && !empty($c['release'])) {
            $h['If-None-Match'] = (string)$c['etag'];
        }
        $r = Http::get($url, $h, max(1.0, self::left($deadline, 15)));
        self::noteRate($c, $r);
        if (($r['status'] === 403 || $r['status'] === 429) && !empty($c['rate']['blocked_until']) && (int)$c['rate']['blocked_until'] > time()) {
            $errors[] = 'GitHub API limit reached';
            $kinds[] = 'limit';
            return null;
        }
        if ($r['status'] === 304 && !empty($c['release'])) {
            $release = $c['release'];
        } elseif ($r['status'] === 200) {
            $j = json_decode($r['body'], true);
            if (!is_array($j) || empty($j['tag_name'])) {
                $errors[] = 'api: unexpected answer';
                $kinds[] = 'other';
                return null;
            }
            if (!empty($j['draft']) || !empty($j['prerelease'])) {
                $errors[] = 'api: newest release is not a final release';
                $kinds[] = 'other';
                return null;
            }
            $release = self::releaseOf($j);
            $c['release'] = $release;
            $c['etag'] = (string)($r['headers']['etag'] ?? '');
        } else {
            $errors[] = 'api: ' . ($r['error'] !== '' ? $r['error'] : 'HTTP ' . $r['status']);
            $kinds[] = self::failKind($r['status'], 'other');
            return null;
        }
        $ver = ltrim((string)$release['tag'], 'vV');
        if (!Util::semverValid($ver)) {
            $errors[] = 'api: release tag is not a version';
            $kinds[] = 'other';
            return null;
        }
        $m = self::releaseManifest($release, $ver, $err, $kind, $deadline);
        if ($m === null && $kind === 'unknown_key') {
            // signed with a key this site does not know yet (a key rotation it missed): the releases before it may carry
            // the signed rotation statement that teaches this site the new key
            $stone = self::keyStone($c, $release, $ver, $deadline);
            if ($stone !== null) {
                return $stone;
            }
        }
        if ($m === null) {
            $errors[] = 'api: ' . $err;
            $kinds[] = $kind === 'unknown_key' ? 'signature' : $kind;
            return null;
        }
        return array($m, $release);
    }

    /**
     * The newest release $newest (version $ver) is signed by a key this site does not know. The releases before it are
     * verified newest first (one API listing): verifying one that carries the signed rotation statement of the new key
     * makes this site remember that key (Signature::verify), after which the newest release verifies and is offered
     * itself; otherwise the newest release that verifies with the keys this site has is offered first (its note says so),
     * and after updating to it (its Keys.php lists the new key) the newest one follows. null when none verifies or the
     * API cannot be asked. Returns [Manifest, release] like viaApi().
     */
    private static function keyStone(array &$c, array $newest, $ver, $deadline)
    {
        $installed = Config::installedVersion();
        $list = self::releaseList($c, $deadline);
        if ($list === null || !Util::semverValid($installed)) {
            return null;
        }
        $cands = array();
        foreach ($list as $rel) {
            $v = ltrim((string)($rel['tag'] ?? ''), 'vV');
            if (!empty($rel['draft']) || !empty($rel['prerelease']) || !Util::semverValid($v)
                || Util::semverCompare($v, $installed) <= 0 || Util::semverCompare($v, $ver) >= 0) {
                continue;
            }
            $cands[$v] = $rel;
        }
        uksort($cands, function ($a, $b) {
            return Util::semverCompare($b, $a);
        });
        foreach ($cands as $v => $rel) {
            if (self::left($deadline, 30) < 2.0) {
                break;
            }
            $m = self::releaseManifest($rel, (string)$v, $err, $kind, $deadline);
            if ($m === null) {
                continue;
            }
            // the statement of a rotation release is remembered now: the newest release may verify after all
            $again = self::releaseManifest($newest, $ver, $err2, $kind2, $deadline);
            if ($again !== null) {
                return array($again, $newest);
            }
            $c['key_step'] = array('newest' => $ver, 'v' => $m->version());
            return array($m, $rel);
        }
        return null;
    }

    /** The API listing of the releases (newest first, kept with its ETag in the check record), or null. */
    private static function releaseList(array &$c, $deadline)
    {
        $cfg = Config::endpoints();
        if ($cfg['api'] === '' || (int)($c['rate']['blocked_until'] ?? 0) > time() || self::left($deadline, 30) < 3.0) {
            return null;
        }
        $h = array('Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28');
        if (!empty($c['list_etag']) && !empty($c['list'])) {
            $h['If-None-Match'] = (string)$c['list_etag'];
        }
        $r = Http::get($cfg['api'] . '/repos/' . $cfg['repo'] . '/releases?per_page=50', $h, max(1.0, self::left($deadline, 15)));
        self::noteRate($c, $r);
        if ($r['status'] === 200) {
            $j = json_decode($r['body'], true);
            $list = array();
            foreach (is_array($j) ? $j : array() as $rel) {
                if (is_array($rel) && !empty($rel['tag_name'])) {
                    $list[] = self::releaseOf($rel);
                }
            }
            $c['list'] = array_slice($list, 0, 50);
            $c['list_etag'] = (string)($r['headers']['etag'] ?? '');
        } elseif ($r['status'] !== 304 || empty($c['list'])) {
            return null;
        }
        return (array)$c['list'];
    }

    /** GitHub's rate-limit headers: shown on the page; a block is remembered only when GitHub says so. */
    private static function noteRate(array &$c, array $r)
    {
        $rem = $r['headers']['x-ratelimit-remaining'] ?? null;
        $old = (int)($c['rate']['blocked_until'] ?? 0);
        if ($rem !== null) {
            $c['rate'] = array('remaining' => (int)$rem, 'reset' => (int)($r['headers']['x-ratelimit-reset'] ?? 0), 'at' => time(),
                'blocked_until' => $old > time() ? $old : 0);
        }
        $retry = isset($r['headers']['retry-after']) && ctype_digit(trim((string)$r['headers']['retry-after'])) ? (int)trim((string)$r['headers']['retry-after']) : null;
        if (($r['status'] === 403 || $r['status'] === 429) && ($rem === '0' || $retry !== null)) {
            $until = $retry !== null ? time() + min(3600, $retry) : (int)($r['headers']['x-ratelimit-reset'] ?? (time() + 3600));
            $c['rate'] = array('remaining' => 0, 'reset' => (int)($r['headers']['x-ratelimit-reset'] ?? $until), 'at' => time(),
                'blocked_until' => min($until, time() + 3600));
        }
    }

    /** The parts of an API release object the updater uses. */
    private static function releaseOf(array $j)
    {
        $assets = array();
        foreach ((array)($j['assets'] ?? array()) as $a) {
            if (is_array($a) && isset($a['name'], $a['browser_download_url'])) {
                $assets[(string)$a['name']] = array('url' => (string)$a['browser_download_url'], 'size' => (int)($a['size'] ?? 0),
                    'digest' => (string)($a['digest'] ?? ''));
            }
        }
        return array('tag' => (string)$j['tag_name'], 'name' => (string)($j['name'] ?? ''), 'html_url' => (string)($j['html_url'] ?? ''),
            'published' => (string)($j['published_at'] ?? ''), 'body' => Util::cut((string)($j['body'] ?? ''), 60000), 'assets' => $assets,
            'draft' => !empty($j['draft']), 'prerelease' => !empty($j['prerelease']));
    }

    /** The verified manifest of API release $release (version $ver): stored copy, or its signed manifest asset. */
    private static function releaseManifest(array $release, $ver, &$err, &$kind, $deadline)
    {
        $err = '';
        $kind = '';
        // a manifest already verified for this version needs no second download
        $stored = self::stored($ver);
        if ($stored !== null) {
            return $stored;
        }
        $name = self::manifestName($ver);
        $assets = (array)$release['assets'];
        if (isset($assets[$name], $assets[$name . '.sig'])) {
            $m = self::fetchPair($assets[$name]['url'], $assets[$name . '.sig']['url'], $err, $assets[$name]['digest'] ?? '', $deadline, $kind);
        } elseif (isset($assets['javcms-release.json'], $assets['javcms-release.json.sig'])) {
            $m = self::fetchPair($assets['javcms-release.json']['url'], $assets['javcms-release.json.sig']['url'], $err, '', $deadline, $kind);
        } else {
            $err = 'the release has no signed manifest';
            $kind = 'other';
            $m = null;
        }
        if ($m !== null && $m->version() !== $ver) {
            $err = 'manifest version ' . $m->version() . ' does not match the release tag ' . $release['tag'];
            $kind = 'signature';
            return null;
        }
        return $m;
    }

    /**
     * The newest final release newer than $installed and older than $newest whose upgrade.min_from allows $installed
     * (one API listing; the answer is kept with its ETag). null when there is none or the API cannot be asked.
     */
    private static function steppingStone(array &$c, $installed, $newest, $deadline)
    {
        $list = self::releaseList($c, $deadline);
        if ($list === null) {
            return null;
        }
        $cands = array();
        foreach ($list as $rel) {
            $v = ltrim((string)($rel['tag'] ?? ''), 'vV');
            if (!empty($rel['draft']) || !empty($rel['prerelease']) || !Util::semverValid($v)
                || Util::semverCompare($v, $installed) <= 0 || Util::semverCompare($v, $newest) >= 0) {
                continue;
            }
            $cands[$v] = $rel;
        }
        uksort($cands, function ($a, $b) {
            return Util::semverCompare($b, $a);
        });
        foreach ($cands as $v => $rel) {
            if (self::left($deadline, 30) < 2.0) {
                break;
            }
            $m = self::releaseManifest($rel, (string)$v, $err, $kind, $deadline);
            if ($m === null) {
                continue;
            }
            $min = $m->upgrade()['min_from'];
            if ($min === '' || Util::semverCompare($installed, $min) >= 0) {
                return $m;
            }
        }
        return null;
    }

    /**
     * Downloads a manifest and its signature, verifies both and stores them. $digest: GitHub's "sha256:..." of the
     * manifest asset when known (an extra check, never a replacement for the signature). $kind gets the sort of failure:
     * 'net' (no answer), 'down' (GitHub answered with a server error, 5xx), 'http' (another status), 'signature' or ''.
     */
    public static function fetchPair($url, $sigUrl, &$error = '', $digest = '', $deadline = null, &$kind = '')
    {
        $error = '';
        $kind = '';
        $deadline = $deadline === null ? microtime(true) + 35.0 : (float)$deadline;
        $a = Http::get($url, array(), max(1.0, self::left($deadline, 20)));
        if ($a['status'] !== 200) {
            $error = 'manifest: ' . ($a['error'] !== '' ? $a['error'] : 'HTTP ' . $a['status']);
            $kind = self::failKind($a['status'], 'http');
            return null;
        }
        $b = Http::get($sigUrl, array(), max(1.0, self::left($deadline, 15)));
        if ($b['status'] !== 200) {
            $error = 'signature: ' . ($b['error'] !== '' ? $b['error'] : 'HTTP ' . $b['status']);
            $kind = self::failKind($b['status'], 'http');
            return null;
        }
        if ($digest !== '' && strncmp($digest, 'sha256:', 7) === 0 && !hash_equals(substr($digest, 7), hash('sha256', $a['body']))) {
            $error = 'manifest differs from the digest GitHub lists';
            $kind = 'signature';
            return null;
        }
        $m = self::accept($a['body'], $b['body'], $error);
        if ($m === null) {
            // a key this site does not know (a rotation it missed) is told apart: the release listing may hold the step
            $kind = strpos($error, 'signed by an unknown key') !== false ? 'unknown_key' : 'signature';
        }
        return $m;
    }

    /** Verifies manifest bytes + signature, parses, stores. Null with $error on any failure. */
    public static function accept($bytes, $sig, &$error = '', $store = true)
    {
        $v = Signature::verify($bytes, $sig);
        if (!$v['ok']) {
            $error = 'signature check failed: ' . $v['error'];
            return null;
        }
        try {
            $m = Manifest::parse($bytes);
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage();
            return null;
        }
        if ($m->data['channel'] !== 'stable' && Config::test() === null) {
            $error = 'not a stable release';
            return null;
        }
        $dir = $store && Config::toolKeys() === null ? self::store() : '';
        if ($dir !== '') {
            Util::writeAtomic($dir . self::manifestName($m->version()), $bytes);
            Util::writeAtomic($dir . self::manifestName($m->version()) . '.sig', $sig);
        }
        return $m;
    }

    /**
     * A stored manifest of $version, verified again (keys may have been revoked since); in the update bridge also the
     * file list it carries for the version it is for (embedded()).
     */
    public static function stored($version)
    {
        if (!Util::semverValid($version)) {
            return null;
        }
        $dir = Util::root() . 'runtime/update/manifests/';
        $file = $dir . self::manifestName($version);
        if (!is_file($file) || !is_file($file . '.sig')) {
            return self::embedded($version);
        }
        $bytes = (string)@file_get_contents($file);
        $sig = (string)@file_get_contents($file . '.sig');
        $v = Signature::verify($bytes, $sig);
        if (!$v['ok']) {
            return null;
        }
        try {
            $m = Manifest::parse($bytes);
        } catch (\InvalidArgumentException $e) {
            return null;
        }
        return $m->version() === $version ? $m : null;
    }

    /**
     * The file list of the version an update bridge is for (1.2.0): the bridge library carries it as base-manifest.json.gz
     * next to itself, built by build_bridge.php from that version's published zip, with its SHA-256 compiled into the
     * library (the constant BRIDGE_BASE_SHA256, which only the bridge library defines: a site's own updater never reads
     * such a file). Like the library itself it needs no signature, so a 1.2.0 site tells its own changes apart from the
     * release whatever keys later releases trust (a key rotation never takes the list away). null when there is none,
     * it does not match, or it is for another version.
     */
    private static function embedded($version)
    {
        static $m = false;
        $const = __NAMESPACE__ . '\\BRIDGE_BASE_SHA256';
        if (!defined($const)) {
            return null;
        }
        if ($m === false) {
            $m = null;
            $gz = @file_get_contents(__DIR__ . '/base-manifest.json.gz');
            $bytes = is_string($gz) && hash_equals((string)constant($const), hash('sha256', $gz)) && function_exists('gzdecode') ? @gzdecode($gz) : false;
            if (is_string($bytes)) {
                try {
                    $m = Manifest::parse($bytes);
                } catch (\InvalidArgumentException $e) {
                    $m = null;
                }
            }
        }
        return $m !== null && $m->version() === (string)$version ? $m : null;
    }

    /** The newest stored manifest (verified), or null. */
    public static function bestStored()
    {
        $best = null;
        foreach ((array)glob(Util::root() . 'runtime/update/manifests/javcms-*.json') as $f) {
            if (!preg_match('#javcms-(.+)\.json$#', (string)$f, $m) || !Util::semverValid($m[1])) {
                continue;
            }
            if ($best === null || Util::semverCompare($m[1], $best) > 0) {
                $best = $m[1];
            }
        }
        return $best === null ? null : self::stored($best);
    }

    /**
     * True when the last check could not reach GitHub at all (nothing answered) within OFFLINE_SECONDS: a host whose
     * firewall drops outgoing connections. Then manifests are looked up in the stored copies and the inbox only.
     */
    public static function offline()
    {
        $at = (int)(self::cached()['gh_unreachable_at'] ?? 0);
        return $at > 0 && time() - $at < self::OFFLINE_SECONDS;
    }

    /** The reason of a failed fetch of $version's manifest within MISS_SECONDS, or ''. */
    public static function missRecent($version)
    {
        $d = Util::loadData(Util::root() . 'runtime/update/misses.php');
        $row = is_array($d) ? ($d[(string)$version] ?? null) : null;
        return is_array($row) && time() - (int)($row['at'] ?? 0) < self::MISS_SECONDS ? (string)($row['error'] ?? 'not found') : '';
    }

    /** Forgets a failed fetch of $version's manifest (a new Review asks GitHub again). */
    public static function forgetMiss($version)
    {
        $f = Util::root() . 'runtime/update/misses.php';
        $d = Util::loadData($f);
        if (is_array($d) && isset($d[(string)$version]) && Util::dir() !== '') {
            unset($d[(string)$version]);
            Util::saveData($f, $d);
        }
    }

    private static function noteMiss($version, $error)
    {
        $dir = Util::dir();
        if ($dir === '') {
            return;
        }
        $d = Util::loadData($dir . 'misses.php');
        $d = is_array($d) ? $d : array();
        foreach ($d as $v => $row) {
            if (!is_array($row) || time() - (int)($row['at'] ?? 0) >= self::MISS_SECONDS) {
                unset($d[$v]);
            }
        }
        $d[(string)$version] = array('at' => time(), 'error' => Util::cut((string)$error, 300));
        Util::saveData($dir . 'misses.php', array_slice($d, -20, null, true));
    }

    /**
     * Manifest of $version: stored copy, else the release's asset on GitHub, else the inbox (one deadline for all). A
     * fetch that got no answer at all is remembered for MISS_SECONDS (the next slice of a Review does not wait for a
     * silent GitHub again; a new Review forgets it: forgetMiss), and while GitHub is unreachable (offline()) or $offline is
     * given, only the stored copies and the inbox are asked. A fetch that got an answer (an error status, a cut or
     * damaged file) is not remembered: the next need asks again.
     */
    public static function manifestFor($version, &$error = '', $deadline = null, $offline = false)
    {
        $m = self::stored($version);
        if ($m !== null) {
            return $m;
        }
        $miss = self::missRecent($version);
        if ($offline || $miss !== '' || self::offline()) {
            $inbox = self::fromInbox($version);
            if ($inbox !== null) {
                return $inbox;
            }
            $error = $miss !== '' ? $miss : 'GitHub could not be reached a moment ago and the inbox has no javcms-' . $version . '.json';
            return null;
        }
        $deadline = $deadline === null ? self::deadline() : (float)$deadline;
        $cfg = Config::endpoints();
        $name = self::manifestName($version);
        $tries = array(
            $cfg['web'] . '/' . $cfg['repo'] . '/releases/download/v' . $version . '/' . $name,
        );
        $errs = array();
        $silent = true;
        foreach ($tries as $url) {
            if (self::left($deadline, 30) < 2.0) {
                $errs[] = Http::show($url) . ': not asked (no time left)';
                continue;
            }
            $kind = '';
            $m = self::fetchPair($url, $url . '.sig', $err, '', $deadline, $kind);
            if ($m !== null && $m->version() === $version) {
                return $m;
            }
            $silent = $silent && $m === null && $kind === 'net';
            $errs[] = Http::show($url) . ': ' . ($m !== null ? 'wrong version' : $err);
        }
        $inbox = self::fromInbox($version);
        if ($inbox !== null) {
            return $inbox;
        }
        $error = implode('; ', $errs);
        if ($silent) {
            self::noteMiss($version, $error);
        }
        return null;
    }

    /** Download address of a release archive: GitHub's tag link (GitHub redirects it to its asset host). */
    public static function zipUrls(Manifest $m)
    {
        $cfg = Config::endpoints();
        $z = $m->zip();
        return array($cfg['web'] . '/' . $cfg['repo'] . '/releases/download/v' . $m->version() . '/' . $z['name']);
    }

    /**
     * Offline inbox: runtime/update/inbox/javcms-<v>.json + .sig (or javcms-release.json + .sig). The newest valid one,
     * or the one of $version.
     */
    public static function fromInbox($version = '')
    {
        $dir = Util::root() . 'runtime/update/inbox/';
        if (!is_dir($dir)) {
            return null;
        }
        $best = null;
        foreach ((array)glob($dir . 'javcms-*.json') as $f) {
            if (!is_file($f . '.sig')) {
                continue;
            }
            $bytes = (string)@file_get_contents($f);
            $m = self::accept($bytes, (string)@file_get_contents($f . '.sig'), $err);
            if ($m === null) {
                continue;
            }
            if ($version !== '' && $m->version() !== $version) {
                continue;
            }
            if ($best === null || Util::semverCompare($m->version(), $best->version()) > 0) {
                $best = $m;
            }
        }
        return $best;
    }

    /** Path of an archive the owner put into the inbox for $m (name and size must match; sha is checked later). */
    public static function inboxZip(Manifest $m)
    {
        $z = $m->zip();
        $f = Util::root() . 'runtime/update/inbox/' . $z['name'];
        return is_file($f) && filesize($f) === $z['size'] ? $f : '';
    }

    private static function rememberSeen($version)
    {
        $file = Util::dir() . 'seen.php';
        $d = Util::loadData($file);
        $old = is_array($d) ? (string)($d['version'] ?? '') : '';
        if ($old === '' || !Util::semverValid($old) || Util::semverCompare($version, $old) > 0) {
            Util::saveData($file, array('version' => $version, 'at' => time()));
        }
    }
}
