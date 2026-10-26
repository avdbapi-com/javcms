<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Based on MacCMS 10 (Apache-2.0) by magicblack, changed by JavCMS.
 * JavCMS changes: Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors,
 * JavCMS Community License 1.0. The MacCMS parts stay under the Apache License 2.0.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
namespace app\admin\controller;

use app\common\util\updater\Config as UpConfig;
use app\common\util\updater\Engine as UpEngine;
use app\common\util\updater\Notes as UpNotes;
use app\common\util\updater\Runner as UpRunner;
use app\common\util\updater\Service as UpService;
use app\common\util\updater\Source as UpSource;
use app\common\util\updater\Store as UpStore;
use app\common\util\updater\Util as UpUtil;

/**
 * Admins & backup > Updates: one-click updates from the GitHub releases of avdbapi-com/javcms (JavCMS 1.2.1).
 *
 * Only the founder admin account (id 1) may open this page or call its actions (other admins get "permission denied"
 * whatever their role ticks). Every action that changes something is a POST with the admin token (__token__ field or
 * X-CSRF-Token header), and starting an update or a rollback asks for the admin password again.
 *
 * The page itself only checks, explains and arms a run. The steps of a running update are answered by the update
 * engine's own entry (runtime/update/runner.php, loaded by the admin entry before the framework: "?jcupd=step"),
 * because while files are being replaced the framework files are half old, half new; while that lasts, every admin
 * page (this one included) is the run's own progress page, which can continue the run. See
 * application/common/util/updater/ (engine), tools/update.php (command line) and the help guide "One-click updates".
 *
 * The old MacCMS online updater (step1 / step2 / step3 / one) stays retired: those actions answer with the reason.
 */
class Update extends Base
{
    public function __construct()
    {
        parent::__construct();
        if ((int)($this->_admin['admin_id'] ?? 0) !== 1) {
            $legacy = in_array(strtolower((string)$this->request->action()), array('step1', 'step2', 'step3', 'one'), true);
            if (!$legacy) {
                $msg = lang('admin/ux/updates/founder_only');
                if ($this->request->isAjax() || $this->request->isPost()) {
                    throw new \think\exception\HttpResponseException(json(array('code' => 0, 'msg' => $msg)));
                }
                throw new \think\exception\HttpResponseException(\think\Response::create($this->errorPage($msg)));
            }
        }
        UpUtil::setRoot(ROOT_PATH);
    }

    private function errorPage($msg)
    {
        $this->assign('title', lang('admin/ux/menu/updates'));
        $this->assign('up_error', $msg);
        $this->assign('ax_head', array('title' => lang('admin/ux/menu/updates'), 'help' => lang('admin/ux/help/updates')));
        return $this->fetch('admin@update/denied');
    }

    // ------------------------------------------------------------------ page

    public function index()
    {
        UpService::housekeep();
        $st = UpService::status();
        $check = $st['check'];
        // runtime/update/ unusable (a link, no permission): said at once, instead of results that cannot be kept
        $problem = UpService::runtimeProblem();
        $this->assign('up_runtime_problem', $problem !== null ? $this->serviceError($problem) : '');
        $notes = '';
        if (!empty($check['available']) && is_string($check['notes'] ?? null)) {
            $notes = UpNotes::render($check['notes']);
        }
        $title = lang('admin/ux/menu/updates');
        $this->assign('title', $title);
        $this->assign('ax_head', array('title' => $title, 'help' => lang('admin/ux/help/updates')));
        $this->assign('up', $st);
        $this->assign('up_notes', $notes);
        $this->assign('up_checked', $check['checked_at'] ? date('Y-m-d H:i', (int)$check['checked_at']) : '');
        $this->assign('up_rollback_until', $st['rollback'] ? date('Y-m-d H:i', (int)$st['rollback']['until']) : '');
        // the database backup of a breaking release was deleted in Backup & restore: only the files can be rolled back
        // (built here: a "{list}" inside a template expression would be read as a template tag)
        $this->assign('up_rollback_db_missing', $st['rollback'] && !empty($st['rollback']['db_missing'])
            ? str_replace('{list}', htmlspecialchars(implode(lang('admin/ux/updates/list_sep'), (array)$st['rollback']['db_missing']), ENT_QUOTES, 'UTF-8'),
                (string)lang('admin/ux/updates/rollback_db_missing')) : '');
        // a failed check: one plain sentence in the admin's language; the technical details stay folded
        $this->assign('up_check_error', $check['error'] !== '' && !$check['available']
            ? $this->text(($check['error_k'] ?? '') !== '' ? (string)$check['error_k'] : 'ce_failed', (array)($check['error_v'] ?? array()), (string)$check['error']) : '');
        $this->assign('up_check_note', (string)($check['note'] ?? '') !== ''
            ? $this->text((string)($check['note_k'] ?? ''), (array)($check['note_v'] ?? array()), (string)$check['note']) : '');
        $history = array();
        foreach (array_slice($st['history'], 0, 20) as $h) {
            $rollback = (string)$h['kind'] === 'rollback';
            $history[] = array(
                'id' => (string)$h['id'], 'kind' => (string)$h['kind'], 'from' => (string)$h['from'], 'to' => (string)$h['to'],
                'change' => $this->text($rollback ? 'h_change_rollback' : 'h_change_update', array('from' => (string)$h['from'], 'to' => (string)$h['to']),
                    ($rollback ? 'Rollback ' : '') . $h['from'] . ' → ' . $h['to']),
                'started' => $h['started'] ? date('Y-m-d H:i', (int)$h['started']) : '', 'status' => (string)$h['status'],
                'status_label' => lang('admin/ux/updates/st_' . preg_replace('/[^a-z_]/', '', (string)$h['status'])),
                // a cancelled run needs no reason next to "Cancelled" (it would say "cancelled by the admin" again)
                'error' => (string)$h['error'] === '' || (string)$h['status'] === 'cancelled' ? ''
                    : $this->reason((string)($h['error_k'] ?? ''), (array)($h['error_v'] ?? array()), (string)$h['error']),
            );
        }
        $this->assign('up_history', $history);
        // a run another page (or the command line) drives right now: this page follows it without asking for a password
        $runState = $st['run'] !== null && $st['run']['status'] === 'running' && ($cur = UpStore::current()) !== null && ($rs = UpStore::load($cur['run'])) !== null
            && UpStore::driven($rs);
        $this->assign('up_js', json_encode(array(
            'urls' => array(
                'check' => url('update/check'), 'settings' => url('update/settings'), 'precheck' => url('update/precheck'),
                'arm' => url('update/arm'), 'rollback' => url('update/rollback'), 'rearm' => url('update/rearm'),
                'cancel' => url('update/cancel'), 'log' => url('update/log'), 'modified' => url('update/modified'), 'index' => url('update/index'),
                // a rollback that waits for the founder: "Try the rollback again" and "Close this run" through this page
                // (the framework checked the founder's sign-in: it works on every host, also where the engine cannot read
                // the session itself)
                'retry' => url('update/retry'), 'close' => url('update/close'),
                'runner' => (string)$_SERVER['SCRIPT_NAME'] . '?jcupd=',
                // the maintenance gate of index.php forwards the steps too (used when a rollback brought back an admin
                // entry that does not forward them)
                'runner_alt' => rtrim(str_replace('\\', '/', dirname((string)$_SERVER['SCRIPT_NAME'])), '/.') . '/index.php?jcupd=',
                'home' => url('index/index'),
            ),
            'csrf' => (string)mac_admin_csrf_token(),
            'run' => $st['run'],
            'run_driven' => $runState,
            'installed' => $st['installed'],
            'latest' => is_array($check['latest']) ? (string)$check['latest']['version'] : '',
            // the card already shows why the last check failed: the page does not say it a second (and third) time
            'check_error_shown' => $check['error'] !== '' && !$check['available'],
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
        $this->assign('up_lang_js', json_encode($this->jsStrings(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
        return $this->fetch('admin@update/index');
    }

    /**
     * Strings of the page script: every string of this page (admin/ux/updates/*, without the prefix) in the admin's
     * language, so progress, log lines and failure reasons, which carry a message key, are shown translated.
     */
    private function jsStrings()
    {
        $out = array();
        $all = \think\Lang::get();
        foreach (is_array($all) ? $all : array() as $k => $v) {
            if (is_string($k) && strpos($k, 'admin/ux/updates/') === 0 && is_string($v)) {
                $out[substr($k, strlen('admin/ux/updates/'))] = $v;
            }
        }
        if (!$out) {
            // a language loader that does not list its strings: the English file of this page
            $out = UpRunner::strings('en-us');
        }
        return $out;
    }

    /** A message key of this page with {name} variables filled in; $fallback (English) when the key has no text. */
    private function text($k, array $v, $fallback)
    {
        if ($k === '') {
            return (string)$fallback;
        }
        $t = lang('admin/ux/updates/' . $k);
        if (!is_string($t) || $t === '' || $t === 'admin/ux/updates/' . $k) {
            return (string)$fallback;
        }
        // a phase id becomes its label; a value with its own message key (<name>_k) is shown in the admin's language
        if (isset($v['phase']) && is_string($v['phase']) && $v['phase'] !== '') {
            $v['phase'] = $this->text('ph_' . $v['phase'], array(), $v['phase']);
        }
        foreach ($v as $n => $x) {
            if (is_string($n) && substr($n, -2) === '_k' && $n !== 'why_k' && is_string($x) && $x !== '') {
                $base = substr($n, 0, -2);
                $v[$base] = $this->text($x, (array)($v[$base . '_v'] ?? array()), (string)($v[$base] ?? ''));
            }
        }
        // a reason inside the reason (why_k / why_v, nested as deep as the engine wrote it: "the rollback could not finish
        // after 12 tries: {why}" with why = "a file could not be put back ({why})" with why = "no backup copy of {file}")
        // in the admin's language too, like the pages' reasonText() (ux-4 P3-2: the history row of a rollback that waits)
        if (isset($v['why_k']) && is_string($v['why_k']) && $v['why_k'] !== '') {
            $v['why'] = $this->reason($v['why_k'], (array)($v['why_v'] ?? array()), (string)($v['why'] ?? ''));
        }
        foreach ($v as $n => $x) {
            if (is_scalar($x)) {
                $t = str_replace('{' . $n . '}', (string)$x, $t);
            }
        }
        return $t;
    }

    /**
     * A refusal of the updater (Service: 'k', 'v', 'error') in the admin's language; a blocked update lists its failed
     * requirements.
     */
    private function serviceError(array $r)
    {
        if (($r['k'] ?? '') === 'blocked' && !empty($r['rows']) && is_array($r['rows'])) {
            $parts = array();
            foreach ($r['rows'] as $row) {
                if (is_array($row)) {
                    $parts[] = $this->text((string)($row['k'] ?? ''), (array)($row['v'] ?? array()), (string)($row['m'] ?? ''));
                }
            }
            return implode(' ', $parts);
        }
        return $this->text((string)($r['k'] ?? ''), (array)($r['v'] ?? array()), (string)($r['error'] ?? lang('admin/ux/updates/failed')));
    }

    /** A failure reason (Engine error_k / error_v) in the admin's language; the health check lists every failed page. */
    private function reason($k, array $v, $fallback)
    {
        if ($k === 'e_health' && is_array($v['checks'] ?? null)) {
            $parts = array();
            foreach ($v['checks'] as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $parts[] = $this->text('hc_' . preg_replace('/[^a-z]/', '', (string)($c['name'] ?? '')), array(), (string)($c['name'] ?? ''))
                    . $this->text('colon', array(), ': ') . $this->text((string)($c['k'] ?? ''), (array)($c['v'] ?? array()), '?')
                    . (!empty($c['browser']) ? ' ' . $this->text('hr_by_browser', array(), '(checked by the browser)') : '');
            }
            if ($parts) {
                $v['list'] = implode($this->text('list_sep', array(), '; '), $parts);
            }
        }
        return $this->text($k, $v, $fallback);
    }

    // ------------------------------------------------------------------ actions (POST + admin token)

    /** Admin token of the session (stable: a dropped reply never leaves the page with a used one). */
    private function tokenOk()
    {
        if (!$this->request->isPost()) {
            return false;
        }
        $post = $this->request->post();
        $t = isset($post['__token__']) && is_string($post['__token__']) ? $post['__token__'] : (string)$this->request->header('X-CSRF-Token');
        return $t !== '' && hash_equals((string)mac_admin_csrf_token(), $t);
    }

    private function reply($code, $msg, array $data = array())
    {
        return json(array('code' => $code, 'msg' => $msg, 'data' => $data), 200, array('Cache-Control' => 'no-store'),
            array('json_encode_param' => JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP));
    }

    /** Guard of every action: POST + token; the session is released before the slow part (other admin tabs keep working). */
    private function guard()
    {
        if (!$this->tokenOk()) {
            return $this->reply(0, lang('admin/ux/system/token_expired'));
        }
        return null;
    }

    private function releaseSession()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        UpUtil::keepRunning();
    }

    /** "Check now" (one deadline for all sources, about 20 s). */
    public function check()
    {
        if (($g = $this->guard()) !== null) {
            return $g;
        }
        $this->releaseSession();
        $problem = UpService::runtimeProblem();
        if ($problem !== null) {
            return $this->reply(0, $this->serviceError($problem), array('check' => array('state' => 'error', 'details' => (string)$problem['error'])));
        }
        try {
            $c = UpSource::check('manual');
        } catch (\Throwable $e) {
            return $this->reply(0, lang('admin/ux/updates/failed') . ' ' . UpUtil::cut(UpUtil::redact($e->getMessage()), 200));
        }
        if (($c['state'] ?? '') === 'error') {
            return $this->reply(0, $this->text(($c['error_k'] ?? '') !== '' ? (string)$c['error_k'] : 'ce_failed', (array)($c['error_v'] ?? array()), (string)$c['error']),
                array('check' => array('state' => 'error', 'details' => (string)$c['error'])));
        }
        $msg = !empty($c['available']) ? str_replace('{v}', (string)$c['latest']['version'], lang('admin/ux/updates/available'))
            : lang('admin/ux/updates/up_to_date');
        if (($c['state'] ?? '') === 'skipped') {
            $msg = lang('admin/ux/updates/checked_recently');
        }
        return $this->reply(1, $msg, array('check' => array('state' => $c['state'], 'available' => !empty($c['available']),
            'latest' => is_array($c['latest']) ? (string)$c['latest']['version'] : '', 'via' => (string)$c['via'],
            'note' => (string)($c['note'] ?? '') !== '' ? $this->text((string)($c['note_k'] ?? ''), (array)($c['note_v'] ?? array()), (string)$c['note']) : '')));
    }

    /** The background check of the admin shell (at most every 12 h, only with automatic checks on). */
    public function notice()
    {
        if (($g = $this->guard()) !== null) {
            return $g;
        }
        $this->releaseSession();
        try {
            $n = UpService::backgroundCheck();
        } catch (\Throwable $e) {
            $n = UpService::notice();
        }
        return $this->reply(1, '', array('available' => $n['available'], 'latest' => $n['latest'], 'installed' => $n['installed'],
            'label' => $n['available'] ? str_replace('{v}', $n['latest'], lang('admin/ux/updates/badge')) : ''));
    }

    /** "Check for updates automatically". */
    public function settings()
    {
        if (($g = $this->guard()) !== null) {
            return $g;
        }
        $on = (string)$this->request->post('auto_check') === '1';
        if (!UpConfig::saveSettings(array('auto_check' => $on))) {
            return $this->reply(0, lang('admin/ux/updates/settings_failed'));
        }
        return $this->reply(1, lang('admin/ux/updates/saved'), array('auto_check' => $on));
    }

    /**
     * One slice of the pre-update check (plan + requirements); the page calls it until state is done. fresh=1 on the
     * first call of a new review: files changed since an earlier review are probed again.
     */
    public function precheck()
    {
        if (($g = $this->guard()) !== null) {
            return $g;
        }
        $this->releaseSession();
        $choice = (string)$this->request->post('choice') === 'keep' ? 'keep' : 'replace';
        try {
            $r = UpService::precheck($choice, microtime(true) + UpUtil::stepBudget(8.0), array(), (string)$this->request->post('fresh') === '1');
        } catch (\Throwable $e) {
            return $this->reply(0, lang('admin/ux/updates/failed') . ' ' . UpUtil::cut(UpUtil::redact($e->getMessage()), 300));
        }
        if ($r['state'] === 'error') {
            return $this->reply(0, $this->serviceError($r));
        }
        return $this->reply(1, '', $r);
    }

    /**
     * Checks the admin password again (five wrong ones pause it for 15 minutes); '' when right, else the message. Only the
     * signed-in founder gets here: the founder's own counter, never the counter of the client's address (strangers behind
     * the same address cannot pause the founder).
     */
    private function passwordOk()
    {
        $raw = $this->request->post('password', '', null);
        $bad = UpRunner::passwordCheck(is_string($raw) ? $raw : '', 'auth-fails.php', false);
        return $bad === '' ? '' : lang('admin/ux/updates/' . $bad);
    }

    /** "Update now": password, choice for changed files, then the run is armed and its token handed to the page. */
    public function arm()
    {
        if (($g = $this->guard()) !== null) {
            return $g;
        }
        if (($problem = UpService::runtimeProblem()) !== null) {
            // said before any password is checked: nothing is written through a link (not even the password counter)
            return $this->reply(0, $this->serviceError($problem));
        }
        $bad = $this->passwordOk();
        if ($bad !== '') {
            return $this->reply(0, $bad, array('password' => 1));
        }
        if ((string)$this->request->post('confirm') !== '1') {
            return $this->reply(0, lang('admin/ux/updates/confirm_needed'));
        }
        $this->releaseSession();
        $r = UpService::arm(array(
            'choice' => (string)$this->request->post('choice') === 'keep' ? 'keep' : 'replace', 'by' => 'admin:' . (int)$this->_admin['admin_id'],
            'base' => UpService::siteBase(), 'admin' => UpService::adminEntry(), 'origin' => UpService::serverAddr(),
        ));
        if (empty($r['ok'])) {
            return $this->reply(0, $this->serviceError($r));
        }
        return $this->reply(1, lang('admin/ux/updates/starting'), array('token' => $r['token'], 'run' => $r['run'], 'kind' => $r['kind']));
    }

    /**
     * "Roll back to <version>": password (and the database choice when the update changed the schema incompatibly).
     * check=1 only checks the password (the page asks "Roll back now?" after a right password, never before).
     */
    public function rollback()
    {
        if (($g = $this->guard()) !== null) {
            return $g;
        }
        if (($problem = UpService::runtimeProblem()) !== null) {
            return $this->reply(0, $this->serviceError($problem));
        }
        $bad = $this->passwordOk();
        if ($bad !== '') {
            return $this->reply(0, $bad, array('password' => 1));
        }
        if ((string)$this->request->post('check') === '1') {
            return $this->reply(1, '', array('checked' => 1));
        }
        $this->releaseSession();
        $r = UpService::armRollback(array(
            'by' => 'admin:' . (int)$this->_admin['admin_id'], 'base' => UpService::siteBase(), 'admin' => UpService::adminEntry(),
            'origin' => UpService::serverAddr(), 'db_restore' => (string)$this->request->post('db_restore') === '1',
        ));
        if (empty($r['ok'])) {
            return $this->reply(0, $this->serviceError($r));
        }
        return $this->reply(1, lang('admin/ux/updates/starting'), array('token' => $r['token'], 'run' => $r['run'], 'kind' => $r['kind']));
    }

    /** A new run token for the running update (the page was reloaded or reopened). */
    public function rearm()
    {
        if (($g = $this->guard()) !== null) {
            return $g;
        }
        if (($problem = UpService::runtimeProblem()) !== null) {
            return $this->reply(0, $this->serviceError($problem));
        }
        $bad = $this->passwordOk();
        if ($bad !== '') {
            return $this->reply(0, $bad, array('password' => 1));
        }
        $r = UpService::rearm();
        if (empty($r['ok'])) {
            return $this->reply(0, $this->serviceError($r));
        }
        return $this->reply(1, '', array('token' => $r['token'], 'run' => $r['run'], 'kind' => $r['kind']));
    }

    /**
     * Cancels a run that has not changed any site file yet. The step that runs on another PHP worker is waited for (a
     * step lasts about 10 s at most); when a longer one (the command line's) holds on, the answer is "pending": the run
     * stops when that step ends, and the page goes on showing it until then.
     */
    public function cancel()
    {
        if (($g = $this->guard()) !== null) {
            return $g;
        }
        $this->releaseSession();
        $r = UpService::cancel();
        if (empty($r['ok'])) {
            return $this->reply(0, $this->serviceError($r), array('k' => (string)($r['k'] ?? '')));
        }
        if (!empty($r['pending'])) {
            return $this->reply(1, $this->serviceError($r), array('pending' => 1));
        }
        return $this->reply(1, lang(!empty($r['db_removed']) ? 'admin/ux/updates/cancelled_db' : 'admin/ux/updates/cancelled'), array('pending' => 0));
    }

    /**
     * The engine of run $id: its frozen copy, taken when the run started (consistent while the site's own files are half
     * restored), else the site's own. Returns the namespace prefix ('\javcms_upd_<id>\' or '\app\common\util\updater\').
     */
    private function runEngine($id)
    {
        $dir = ROOT_PATH . 'runtime/update/engine-' . $id . '/';
        if (preg_match('/^[0-9a-f]{16}$/', (string)$id)) {
            if (is_file($dir . 'bootstrap.php')) {
                require_once $dir . 'bootstrap.php';
                if (class_exists('\\javcms_upd_' . $id . '\\Engine', false)) {
                    return '\\javcms_upd_' . $id . '\\';
                }
            } elseif (is_file($dir . 'bridge-lib.php')) {
                if (!defined('JAVCMS_UPD_LIB')) {
                    define('JAVCMS_UPD_LIB', 1);
                }
                require_once $dir . 'bridge-lib.php';
                if (class_exists('\\javcms_upd_bridge\\Engine', false)) {
                    return '\\javcms_upd_bridge\\';
                }
            }
        }
        return '\\app\\common\\util\\updater\\';
    }

    /**
     * "Try the rollback again" of a rollback that waits for the founder (the run's own page offers it too while the admin
     * pages are held): password, then the rollback goes on from the step that failed and the page drives it with a new
     * run token.
     */
    public function retry()
    {
        if (($g = $this->guard()) !== null) {
            return $g;
        }
        $bad = $this->passwordOk();
        if ($bad !== '') {
            return $this->reply(0, $bad, array('password' => 1));
        }
        $this->releaseSession();
        $cur = UpStore::current();
        $s = $cur !== null ? UpStore::load($cur['run']) : null;
        if ($s === null || $s['status'] !== 'attention') {
            return $this->reply(0, lang('admin/ux/updates/retry_not_now'), array('k' => 'retry_not_now'));
        }
        $ns = $this->runEngine($s['id']);
        $engine = $ns . 'Engine';
        $store = $ns . 'Store';
        if (!$engine::retryAttention($s['id'])) {
            return $this->reply(0, lang('admin/ux/updates/busy'), array('k' => 'busy'));
        }
        $r = UpService::rearm();
        if (empty($r['ok'])) {
            return $this->reply(0, $this->serviceError($r));
        }
        $seq = (int)$this->request->post('seq');
        $now = $store::load($s['id']);
        $out = $now !== null ? $engine::result($now) : array('status' => 'missing');
        $out['token'] = $r['token'];
        $out['log'] = $store::readLog($s['id'], $seq, 200);
        $out['seq'] = $seq + count($out['log']);
        return $this->reply(1, '', $out);
    }

    /**
     * "Close this run" of a rollback that waits for the founder, after the founder finished it by hand (the files of
     * runtime/update/runs/<run>/attention.txt back): password, then the run ends as closed (Engine::closeAttention: the
     * maintenance flag goes, the version number is the old one again, the files still not back are named).
     */
    public function close()
    {
        if (($g = $this->guard()) !== null) {
            return $g;
        }
        $bad = $this->passwordOk();
        if ($bad !== '') {
            return $this->reply(0, $bad, array('password' => 1));
        }
        $this->releaseSession();
        $cur = UpStore::current();
        $s = $cur !== null ? UpStore::load($cur['run']) : null;
        if ($s === null || $s['status'] !== 'attention') {
            return $this->reply(0, lang('admin/ux/updates/close_not_now'), array('k' => 'close_not_now'));
        }
        $ns = $this->runEngine($s['id']);
        $engine = $ns . 'Engine';
        $store = $ns . 'Store';
        $r = $engine::closeAttention($s['id'], 'founder');
        if (empty($r['ok'])) {
            return $this->reply(0, $this->text((string)($r['k'] ?? ''), array(), (string)($r['error'] ?? '')), array('k' => (string)($r['k'] ?? '')));
        }
        $seq = (int)$this->request->post('seq');
        $now = $store::load($s['id']);
        $out = $now !== null ? $engine::result($now) : array('status' => 'closed');
        $out['log'] = $store::readLog($s['id'], $seq, 200);
        $out['seq'] = $seq + count($out['log']);
        return $this->reply(1, '', $out);
    }

    /** The log of a run as a text file (no secrets are ever logged). */
    public function log()
    {
        $id = (string)$this->request->get('run', '');
        if (!preg_match('/^[0-9a-f]{16}$/', $id) || UpStore::load($id) === null) {
            return $this->error(lang('admin/ux/updates/log_missing'));
        }
        $lines = array();
        foreach (UpStore::readLog($id, 0, 100000) as $l) {
            $lines[] = gmdate('Y-m-d H:i:s', (int)$l['t']) . ' UTC  ' . str_pad((string)$l['l'], 5) . ' ' . str_pad((string)$l['p'], 13) . ' ' . (string)$l['m'];
        }
        return $this->sendText('javcms-update-' . $id . '.log', implode("\n", $lines) . "\n");
    }

    /** The full list of release files this site changed (from the last pre-check). */
    public function modified()
    {
        return $this->sendText('javcms-changed-files.txt', UpService::modifiedList());
    }

    private function sendText($name, $text)
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        echo $text;
        exit;
    }

    // ------------------------------------------------------------------ the retired MacCMS online updater

    public function step1($file = '')
    {
        return $this->legacyUpdateDisabled();
    }

    public function step2()
    {
        return $this->legacyUpdateDisabled();
    }

    public function step3()
    {
        return $this->legacyUpdateDisabled();
    }

    public function one()
    {
        return $this->legacyUpdateDisabled();
    }

    private function legacyUpdateDisabled()
    {
        return $this->error(lang('admin/ux/updates/legacy_retired'));
    }
}
