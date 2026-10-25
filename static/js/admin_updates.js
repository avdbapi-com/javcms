/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
/*
 * Admins & backup > Updates (JavCMS 1.2.1): check, review, update with one click, live progress, rollback.
 * The steps of a running update go to the update engine's own entry ("<admin entry>?jcupd=step", header
 * X-Javcms-Update-Token): short requests, each a slice of about 10 s; a dropped connection just continues. The run token
 * lives only in this tab (sessionStorage); a page that lost it continues the run with the founder password (runner action
 * "continue", which works while files are being replaced too: then every admin page is the run's own progress page).
 * Messages, log lines and failure reasons carry a key and are shown in the admin's language (window.AX_UP_LANG).
 */
(function () {
  'use strict';
  var C = window.AX_UP || {}, L = window.AX_UP_LANG || {}, root = document.getElementById('up-page');
  if (!root || !C.urls) { return; }
  var $ = function (id) { return document.getElementById(id); };
  var KEY = 'javcms-update-run', FLASH = 'javcms-update-flash';
  var running = false, seq = 0, token = '', runId = '', runKind = '', runTo = '', netFails = 0, lastPre = null, cancelling = false;
  // every log line of the run this page shows (for the log file made in this browser when the version left behind has no
  // Updates page to download it from)
  var logRows = [];
  // the steps go to the admin entry; when it answers with something else than the runner's JSON (a rollback brought back
  // an entry without the updater's hook), the maintenance gate of index.php takes them
  var runnerUrl = C.urls.runner;
  function runnerSwitch() {
    runnerUrl = (runnerUrl === C.urls.runner && C.urls.runner_alt) ? C.urls.runner_alt : C.urls.runner;
  }

  // ---------------------------------------------------------------- strings
  function fmt(text, vars) {
    text = String(text == null ? '' : text);
    if (vars) {
      Object.keys(vars).forEach(function (k) {
        var v = vars[k];
        if (v !== null && typeof v === 'object') { return; }
        text = text.split('{' + k + '}').join(String(v == null ? '' : v));
      });
    }
    return text;
  }
  function tr(k, vars, fallback) {
    var t = L[k];
    return fmt(t != null && t !== '' && t !== k ? t : (fallback != null ? fallback : k), vars);
  }
  /**
   * Two pieces of a sentence: a space between them, but none after a full-width stop or before a full-width bracket
   * (Chinese text: "完成。已回滚", "HTTP 500（由浏览器检查）" - ux-5 P3-7).
   */
  function join2(a, b) {
    if (!a) { return b || ''; }
    if (!b) { return a; }
    return /[　-〿！-／：-＠]$/.test(a) || /^[　-〿（［｛]/.test(b) ? a + b : a + ' ' + b;
  }
  /** The failed pages of a health check (['name', 'k', 'v', 'browser']), each in the admin's language (its own colon). */
  function checksText(checks) {
    return (checks || []).map(function (c) {
      return join2(tr('hc_' + c.name, null, c.name) + tr('colon', null, ': ') + tr(c.k, c.v || {}, '?'), c.browser ? tr('hr_by_browser') : '');
    }).join(tr('list_sep', null, '; '));
  }
  /** True when version v is older than 1.2.1, the first version with this page (1.2.1-dev and the like count as 1.2.1). */
  function beforeUpdater(v) {
    var m = /^(\d+)\.(\d+)\.(\d+)/.exec(String(v || ''));
    if (!m) { return false; }
    var a = [+m[1], +m[2], +m[3]], b = [1, 2, 1];
    for (var i = 0; i < 3; i++) { if (a[i] !== b[i]) { return a[i] < b[i]; } }
    return false;
  }
  /**
   * Message variables in the admin's language: "<name>_k" (+ "<name>_v") translates "<name>", a phase id becomes its
   * label, a reason (why_k / why_v) or a list of failed pages (checks) becomes {why}, and a line of the new release's
   * upgrade steps carries its own translations (line_t: locale => text).
   */
  function withKeys(vars) {
    var out = {};
    vars = vars || {};
    Object.keys(vars).forEach(function (x) { out[x] = vars[x]; });
    Object.keys(vars).forEach(function (x) {
      if (x !== 'why_k' && x.length > 2 && x.slice(-2) === '_k' && typeof vars[x] === 'string' && vars[x] !== '') {
        var base = x.slice(0, -2);
        out[base] = tr(vars[x], vars[base + '_v'] || {}, vars[base]);
      }
    });
    if (typeof vars.phase === 'string' && vars.phase !== '') { out.phase = tr('ph_' + vars.phase, null, vars.phase); }
    if (vars.why_k) { out.why = reasonText(vars.why_k, vars.why_v, vars.why); }
    else if (Array.isArray(vars.checks) && vars.checks.length) { out.why = checksText(vars.checks); }
    // the pages that still fail after a rollback (health_checks) become {health}
    if (Array.isArray(vars.health_checks) && vars.health_checks.length) { out.health = checksText(vars.health_checks); }
    if (vars.line_t && L.locale_code && typeof vars.line_t[L.locale_code] === 'string') { out.line = vars.line_t[L.locale_code]; }
    return out;
  }
  /** A failure reason (Engine error_k / error_v); the health check lists every failed page (the English list otherwise). */
  function reasonText(k, v, m) {
    v = v || {};
    if (!k) { return String(m || ''); }
    var vars = withKeys(v);
    if (Array.isArray(v.checks) && v.checks.length) { vars.list = checksText(v.checks); }
    return tr(k, vars, m);
  }
  function msgText(m) {
    if (!m) { return ''; }
    // a run that has not reported yet says only its phase ("phase_<id>"): the phase label
    if (String(m.k).indexOf('phase_') === 0) { return tr('ph_' + String(m.k).slice(6), null, m.m); }
    return tr('m_' + m.k, withKeys(m.v), m.m);
  }
  function lineText(l) {
    if (!l.k) { return String(l.m || ''); }
    if (String(l.k).indexOf('e_') === 0) { return reasonText(l.k, l.v, l.m); }
    return tr(l.k, withKeys(l.v), l.m);
  }

  // ---------------------------------------------------------------- small helpers
  function say(el, text, kind) {
    if (!el) { return; }
    el.textContent = text || '';
    el.className = 'up-msg' + (kind === 'bad' ? ' is-bad' : (kind === 'ok' ? ' is-ok' : ''));
    if (text && window.AxUX && window.AxUX.toast) { window.AxUX.toast(text, kind === 'bad' ? 'error' : 'ok'); }
  }
  /** Busy buttons keep the keyboard focus (aria-disabled instead of disabled) and refuse a second press. */
  function busy(btn, on) {
    if (!btn) { return; }
    var span = btn.querySelector('span') || btn;
    if (on) {
      btn.setAttribute('aria-disabled', 'true');
      btn.setAttribute('aria-busy', 'true');
      if (!btn.dataset.label) { btn.dataset.label = span.textContent; }
      span.textContent = L.working || '…';
    } else {
      btn.removeAttribute('aria-disabled');
      btn.removeAttribute('aria-busy');
      if (btn.dataset.label) { span.textContent = btn.dataset.label; delete btn.dataset.label; }
    }
  }
  function isBusy(btn) { return btn && btn.getAttribute('aria-busy') === 'true'; }
  /** The kit's confirm dialog, with buttons that say what they do ("Cancel the update" / "Keep updating"). */
  function confirmBox(message, danger, okLabel, cancelLabel) {
    if (window.AxUX && window.AxUX.confirm) {
      var o = {message: message, danger: !!danger};
      if (okLabel) { o.confirmLabel = okLabel; }
      if (cancelLabel) { o.cancelLabel = cancelLabel; }
      return window.AxUX.confirm(o);
    }
    return Promise.resolve(window.confirm(message));
  }
  function post(url, data, timeoutMs, headers) {
    var body = new URLSearchParams();
    body.append('__token__', C.csrf || '');
    Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
    var ctl = window.AbortController ? new AbortController() : null;
    var timer = ctl ? setTimeout(function () { ctl.abort(); }, timeoutMs || 60000) : null;
    var h = {'X-CSRF-Token': C.csrf || '', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'};
    Object.keys(headers || {}).forEach(function (k) { h[k] = headers[k]; });
    return fetch(url, {method: 'POST', credentials: 'same-origin', body: body, signal: ctl ? ctl.signal : undefined, headers: h})
      .then(function (r) { return r.json(); })
      .then(function (j) { if (timer) { clearTimeout(timer); } return j; },
        function (e) { if (timer) { clearTimeout(timer); } throw e; });
  }
  function focusEl(el) {
    if (!el) { return; }
    try { el.focus({preventScroll: false}); } catch (e) { el.focus(); }
  }
  function flash(text, kind, check) {
    try { sessionStorage.setItem(FLASH, JSON.stringify({text: text || '', kind: kind || 'ok', check: !!check})); } catch (e) { /* private mode */ }
  }

  // a message left by the previous page (cancel and "Check now" reload the page); a failed check is not said again when
  // the status card shows its reason already
  try {
    var fl = sessionStorage.getItem(FLASH);
    if (fl) {
      sessionStorage.removeItem(FLASH);
      var fj = null;
      try { fj = JSON.parse(fl); } catch (e) { fj = {text: fl, kind: 'ok'}; }
      if (fj && fj.text && !(fj.check && fj.kind === 'bad' && C.check_error_shown)) { say($('up-msg'), fj.text, fj.kind === 'bad' ? 'bad' : 'ok'); }
    }
  } catch (e) { /* private mode */ }

  // ---------------------------------------------------------------- status card
  root.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-up]');
    if (!btn || isBusy(btn)) { return; }
    var act = btn.getAttribute('data-up');
    if (act === 'check') { checkNow(btn); }
    else if (act === 'review') { review(btn); }
    else if (act === 'cancel') { cancelRun(btn); }
  });
  root.addEventListener('change', function (ev) {
    var box = ev.target.closest('[data-up="auto"]');
    if (!box) { return; }
    box.disabled = true;
    post(C.urls.settings, {auto_check: box.checked ? '1' : '0'}).then(function (j) {
      say($('up-msg'), j.msg, j.code === 1 ? 'ok' : 'bad');
      if (j.code !== 1) { box.checked = !box.checked; }
    }, function () { box.checked = !box.checked; say($('up-msg'), L.failed, 'bad'); }).then(function () { box.disabled = false; focusEl(box); });
  });
  function checkNow(btn) {
    busy(btn, true);
    say($('up-msg'), L.checking || '');
    post(C.urls.check, {}, 60000).then(function (j) {
      say($('up-msg'), j.msg, j.code === 1 ? 'ok' : 'bad');
      // the status card shows what the check found (or why it failed) after a reload; the message travels along
      if (j.code === 1 && j.data && j.data.check && j.data.check.state !== 'skipped') {
        flash(j.msg, 'ok');
        setTimeout(function () { location.reload(); }, 900);
      } else if (j.code !== 1) {
        flash(j.msg, 'bad', true);
        setTimeout(function () { location.reload(); }, 1500);
      }
    }, function () { say($('up-msg'), L.failed, 'bad'); }).then(function () { busy(btn, false); });
  }

  // ---------------------------------------------------------------- review (pre-check slices)
  function choice() {
    var r = root.querySelector('input[name="up-choice"]:checked');
    return r ? r.value : 'replace';
  }
  /** $keep: the element that keeps the focus when the review ends (the radio a new choice started it from). */
  function review(btn, keep) {
    var box = $('up-review');
    if (!box) { return; }
    box.hidden = false;
    if (!keep) { box.scrollIntoView({behavior: 'smooth', block: 'start'}); }
    busy(btn, true);
    $('up-pre-body').hidden = !!keep ? $('up-pre-body').hidden : true;
    $('up-pre-text').textContent = L.precheck_running || '';
    var fresh = '1';
    var step = function () {
      post(C.urls.precheck, {choice: choice(), fresh: fresh}, 60000).then(function (j) {
        fresh = '0';
        if (j.code !== 1) { $('up-pre-text').textContent = j.msg; busy(btn, false); return; }
        var d = j.data || {};
        var bar = $('up-pre-bar');
        if (d.total > 0) { bar.max = d.total; bar.value = d.done; }
        if (d.state === 'more') {
          $('up-pre-text').textContent = d.stage === 'write' ? tr('precheck_write', {done: d.done, total: d.total})
            : (L.precheck_running || '') + ' ' + d.done + ' / ' + d.total;
          setTimeout(step, 100);
          return;
        }
        var blocked = showPrecheck(d);
        $('up-pre-text').textContent = blocked ? '' : (L.precheck_done || '');
        busy(btn, false);
        // a review the owner's choice started keeps the focus on that choice (nothing jumps under the keyboard)
        focusEl(keep && document.contains(keep) ? keep : $('up-review-title'));
      }, function () { $('up-pre-text').textContent = L.failed; busy(btn, false); });
    };
    step();
  }
  function li(level, text) {
    var item = document.createElement('li');
    var chip = document.createElement('span');
    chip.className = 'ax-chip ' + (level === 'ok' ? 'ax-chip--ok' : (level === 'warn' ? 'ax-chip--warn' : 'ax-chip--danger'));
    var vis = document.createElement('span');
    vis.setAttribute('aria-hidden', 'true');
    vis.textContent = level === 'ok' ? 'OK' : (level === 'warn' ? '!' : '×');
    var sr = document.createElement('span');
    sr.className = 'ax-sr-only';
    sr.textContent = tr(level === 'ok' ? 'req_ok' : (level === 'warn' ? 'req_warn' : 'req_error'));
    chip.appendChild(vis);
    chip.appendChild(sr);
    var span = document.createElement('span');
    span.textContent = text;
    item.appendChild(chip);
    item.appendChild(span);
    return item;
  }
  function showPrecheck(d) {
    lastPre = d;
    var req = $('up-req'), blocking = false;
    req.textContent = '';
    (d.requirements || []).forEach(function (r) {
      if (r.level === 'error') { blocking = true; }
      req.appendChild(li(r.level, tr(r.k, r.v, r.m)));
    });
    var p = d.plan || {}, plan = $('up-plan');
    plan.textContent = '';
    [['add', 'plan_add'], ['replace', 'plan_replace'], ['delete', 'plan_delete'], ['modified', 'plan_modified'], ['merge', 'plan_merge'], ['unreadable', 'plan_unreadable'], ['same', 'plan_same']].forEach(function (x) {
      var n = p[x[0]] || 0;
      if ((x[0] === 'merge' || x[0] === 'unreadable') && !n) { return; }
      var div = document.createElement('div'), b = document.createElement('b'), s = document.createElement('span');
      b.textContent = String(n);
      s.textContent = tr(x[1]);
      div.appendChild(b);
      div.appendChild(s);
      plan.appendChild(div);
    });
    var mod = $('up-modified');
    if ((p.modified || 0) > 0) {
      mod.hidden = false;
      $('up-modified-text').textContent = tr('confirm_modified', {n: p.modified});
      $('up-modified-list').textContent = (p.modified_list || []).join('\n') + ((p.modified || 0) > (p.modified_list || []).length ? '\n…' : '');
      $('up-modified-dl').href = C.urls.modified;
      // the updater's own files among them are replaced whatever the choice (the backup keeps the site's copies)
      var nd = $('up-needed');
      if (nd) {
        nd.hidden = !(p.needed > 0);
        nd.textContent = p.needed > 0 ? tr('needed_line', {n: p.needed, list: (p.needed_list || []).slice(0, 8).join(', ') + (p.needed > 8 ? ', …' : '')}) : '';
      }
    } else {
      mod.hidden = true;
    }
    var dbText = '';
    if (d.db) {
      dbText = tr(d.db.full ? 'plan_db_full' : 'plan_db_part', {tables: d.db.tables, size: (d.db.bytes / 1048576).toFixed(1) + ' MB', folder: d.db.folder});
    } else {
      dbText = tr('plan_db_none');
    }
    if (d.compat === 'breaking') { dbText += ' ' + tr('plan_compat_breaking'); }
    if (p.unknown_base) { dbText += ' ' + tr('plan_unknown_base'); }
    $('up-db-text').textContent = dbText;
    // how long it takes, and how long visitors see the maintenance page (the confirmation names the same time)
    var est = d.estimate || null, eLine = $('up-estimate');
    if (est && eLine) {
      eLine.textContent = tr('est_line', {files: est.files, min: est.minutes, mmin: est.maint_minutes});
      eLine.hidden = false;
      if ($('up-confirm-text')) { $('up-confirm-text').textContent = tr('confirm_text_est', {mmin: est.maint_minutes}); }
    }
    $('up-pre-body').hidden = false;
    $('up-blocked').hidden = !blocking;
    $('up-arm-form').hidden = blocking;
    return blocking;
  }
  root.addEventListener('change', function (ev) {
    if (ev.target && ev.target.name === 'up-choice' && lastPre) {
      // the plan numbers depend on the choice: compute again (the focus stays on the choice)
      var btn = root.querySelector('[data-up="review"]');
      review(btn, ev.target);
    }
  });
  var armForm = $('up-arm-form');
  if (armForm) {
    armForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var pwd = $('up-password'), btn = $('up-arm-btn');
      if (isBusy(btn)) { return; }
      if (!$('up-confirm').checked) { say($('up-arm-msg'), L.confirm_needed || L.password_needed, 'bad'); focusEl($('up-confirm')); return; }
      if (!pwd.value) { say($('up-arm-msg'), L.password_needed, 'bad'); focusEl(pwd); return; }
      busy(btn, true);
      post(C.urls.arm, {password: pwd.value, choice: choice(), confirm: '1'}, 60000).then(function (j) {
        pwd.value = '';
        busy(btn, false);
        if (j.code !== 1) { say($('up-arm-msg'), j.msg, 'bad'); focusEl(pwd); return; }
        startRun(j.data.token, j.data.run, j.data.kind, C.latest);
      }, function () { say($('up-arm-msg'), L.failed, 'bad'); busy(btn, false); });
    });
  }

  // ---------------------------------------------------------------- rollback: password first, then "Roll back now?"
  var rbForm = $('up-rollback-form');
  if (rbForm) {
    rbForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var pwd = $('up-rb-password'), btn = rbForm.querySelector('button');
      if (isBusy(btn)) { return; }
      if (!pwd.value) { say($('up-rb-msg'), L.password_needed, 'bad'); focusEl(pwd); return; }
      var db = $('up-db-restore');
      busy(btn, true);
      post(C.urls.rollback, {password: pwd.value, check: '1'}, 60000).then(function (j) {
        if (j.code !== 1) { pwd.value = ''; busy(btn, false); say($('up-rb-msg'), j.msg, 'bad'); focusEl(pwd); return null; }
        // the right password: an earlier "not correct" goes before the question is asked
        say($('up-rb-msg'), '', '');
        return confirmBox(L.rollback_confirm || '', true, L.rollback_go || '', L.rollback_not_now || '').then(function (yes) {
          if (!yes) { busy(btn, false); focusEl(btn); return null; }
          return post(C.urls.rollback, {password: pwd.value, db_restore: db && db.checked ? '1' : '0'}, 60000).then(function (k) {
            pwd.value = '';
            busy(btn, false);
            if (k.code !== 1) { say($('up-rb-msg'), k.msg, 'bad'); focusEl(pwd); return; }
            startRun(k.data.token, k.data.run, k.data.kind, rbForm.getAttribute('data-to') || '');
          });
        });
      }).catch(function () { say($('up-rb-msg'), L.failed, 'bad'); busy(btn, false); });
    });
  }

  // ---------------------------------------------------------------- running a run
  /** Heading of a run: an update says where it goes; a manual or automatic rollback says where it goes back to. */
  function heading(r) {
    if (r && (r.kind === 'rollback' || r.mode === 'rollback')) {
      return tr('run_title_rollback_js', {v: r.kind === 'rollback' ? (r.to || runTo) : (r.from || '')});
    }
    var ver = r && r.to ? r.to : runTo;
    return ver ? tr('run_title_js', {v: ver}) : (L.running || '');
  }
  function startRun(tok, id, kind, ver) {
    token = tok;
    runId = id;
    runKind = kind || 'update';
    runTo = ver || '';
    seq = 0;
    cancelling = false;
    try { sessionStorage.setItem(KEY, JSON.stringify({run: id, token: tok})); } catch (e) { /* private mode: this tab only */ }
    ['up-release', 'up-review', 'up-rollback'].forEach(function (x) { if ($(x)) { $(x).hidden = true; } });
    var box = $('up-run');
    box.hidden = false;
    $('up-run-heading').textContent = heading({kind: runKind, mode: runKind, to: ver});
    $('up-keep-open').hidden = false;
    $('up-log').textContent = '';
    logRows = [];
    $('up-log-dl').href = C.urls.log + '?run=' + encodeURIComponent(id);
    $('up-log-dl').hidden = false;
    box.scrollIntoView({behavior: 'smooth', block: 'start'});
    focusEl($('up-run-title'));
    running = true;
    loop();
  }
  /**
   * Phase list: done / now / waiting; a run that stopped marks the phases before its failed one done and that one failed;
   * a rollback that waits for the founder (or that the founder closed) the phase where it stopped.
   */
  function phases(list, current, status, skipped, failedAt) {
    var ol = $('up-phases');
    ol.textContent = '';
    var seen = false;
    var stopAt = (status === 'failed' || status === 'cancelled') ? (failedAt || current) : ((status === 'attention' || status === 'closed') ? current : '');
    (list || []).forEach(function (p) {
      var item = document.createElement('li'), st = '';
      if (skipped && skipped.indexOf(p) >= 0) { st = 'skip'; }
      else if (stopAt) { if (p === stopAt) { st = 'fail'; seen = true; } else if (!seen) { st = 'done'; } }
      else if (status !== 'running' && status !== undefined) { st = (status === 'done' || status === 'rolled_back') ? 'done' : ''; }
      else if (p === current) { st = 'now'; item.setAttribute('aria-current', 'step'); seen = true; }
      else if (!seen) { st = 'done'; }
      item.className = st ? 'is-' + st : '';
      item.appendChild(document.createTextNode(tr('ph_' + p, null, p)));
      var sr = document.createElement('span');
      sr.className = 'ax-sr-only';
      sr.textContent = ' (' + tr(st === 'done' ? 'phase_done' : (st === 'now' ? 'phase_now' : (st === 'skip' ? 'phase_skipped'
        : (st === 'fail' ? (status === 'cancelled' ? 'phase_cancelled' : 'phase_failed') : 'phase_wait')))) + ')';
      item.appendChild(sr);
      ol.appendChild(item);
    });
  }
  function addLog(lines) {
    var ol = $('up-log');
    (lines || []).forEach(function (l) {
      logRows.push(l);
      var item = document.createElement('li');
      item.className = l.l === 'warn' ? 'is-warn' : (l.l === 'error' ? 'is-error' : '');
      var t = document.createElement('time');
      var d = new Date((l.t || 0) * 1000);
      t.textContent = ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2) + ':' + ('0' + d.getSeconds()).slice(-2);
      item.appendChild(t);
      item.appendChild(document.createTextNode(lineText(l)));
      ol.appendChild(item);
    });
    ol.scrollTop = ol.scrollHeight;
  }
  /** True while the run may still be cancelled: an update that has not turned the maintenance page on. */
  function cancellable(r) {
    var early = ['scan', 'download', 'verify_zip', 'stage', 'backup_files', 'backup_db'];
    return r.status === 'running' && r.mode === 'update' && (early.indexOf(r.phase) >= 0 || (r.phase === 'maint_on' && !r.maintenance));
  }
  function render(r) {
    if (r.kind) { runKind = r.kind; }
    if (r.to && !runTo) { runTo = r.to; }
    phases(r.phases, r.phase, r.status, r.skipped, r.error_phase);
    // a run the founder closed: its phases ("Maintenance off" waiting ...) no longer say anything true (ux-5 P3-4)
    $('up-phases').hidden = r.status === 'closed';
    $('up-bar').hidden = r.status === 'closed';
    var pr = r.progress || {};
    var bar = $('up-bar');
    if (r.status === 'attention' || r.status === 'closed') {
      // a rollback that stopped keeps the bar where it stopped (its phase among the rollback's phases)
      var at = (r.phases || []).indexOf(r.phase);
      bar.max = Math.max(1, (r.phases || []).length);
      bar.value = Math.max(0, at);
    }
    else if (r.status && r.status !== 'running') { bar.max = 100; bar.value = 100; }
    else if (pr.total > 0) { bar.max = pr.total; bar.value = Math.min(pr.done, pr.total); }
    else { bar.removeAttribute('value'); }
    if (!cancelling) { $('up-now').textContent = r.health_probe ? tr('checking_site') : msgText(r.msg); }
    $('up-maint').hidden = !r.maintenance;
    if (r.status === 'running') { $('up-run-heading').textContent = heading(r); }
    $('up-cancel').hidden = !cancellable(r) || cancelling;
  }
  /**
   * The version left behind predates this page (a rollback to 1.2.0 after the update bridge). Every other card talks to
   * this version's Updates pages - "Check now" and the automatic check, the history and its log links, the help naming
   * tools/update.php - which that version does not have, so only the run's card stays; the run's log is offered as a file
   * made in this browser from the lines it received, in the format of the server's log download.
   */
  function leftBehind(r) {
    Array.prototype.forEach.call(document.querySelectorAll('#up-page .up-card'), function (c) {
      if (c.id !== 'up-run') { c.hidden = true; }
    });
    var dl = $('up-log-dl');
    if (!dl) { return; }
    var pad = function (s, n) { s = String(s == null ? '' : s); while (s.length < n) { s += ' '; } return s; };
    var two = function (n) { return ('0' + n).slice(-2); };
    var text = logRows.map(function (l) {
      var d = new Date((+l.t || 0) * 1000);
      var when = d.getUTCFullYear() + '-' + two(d.getUTCMonth() + 1) + '-' + two(d.getUTCDate()) + ' ' + two(d.getUTCHours()) + ':'
        + two(d.getUTCMinutes()) + ':' + two(d.getUTCSeconds()) + ' UTC';
      return when + '  ' + pad(l.l, 5) + ' ' + pad(l.p, 13) + ' ' + String(l.m == null ? lineText(l) : l.m);
    }).join('\n') + '\n';
    try {
      dl.href = URL.createObjectURL(new Blob([text], {type: 'text/plain;charset=utf-8'}));
      dl.setAttribute('download', 'javcms-update-' + String(runId || 'run').replace(/[^0-9a-z]/gi, '') + '.log');
      dl.hidden = !logRows.length;
    } catch (e) {
      dl.hidden = true;
    }
  }
  function finish(r) {
    running = false;
    cancelling = false;
    try { sessionStorage.removeItem(KEY); } catch (e) { /* ignore */ }
    render(r);
    $('up-keep-open').hidden = true;
    $('up-cancel').hidden = true;
    var good = r.status === 'done' || (r.status === 'rolled_back' && r.kind === 'rollback');
    var attention = r.status === 'rollback_failed' || r.status === 'attention';
    var head = r.status === 'done' ? tr('done_heading') : (r.status === 'rolled_back' ? (r.kind === 'rollback' ? tr('rollback_done_heading') : tr('rolled_back_heading'))
      : (attention ? tr('needs_attention_heading') : (r.status === 'cancelled' ? tr('cancelled_heading') : (r.status === 'closed' ? tr('closed_heading') : tr('failed_heading')))));
    $('up-run-heading').textContent = head;
    // the result notice carries the last message: the bold line above it does not say it a second time (ux-4 P3-13)
    $('up-now').textContent = '';
    var box = $('up-result');
    box.hidden = false;
    box.textContent = '';
    var note = document.createElement('div');
    note.className = 'ax-notice ' + (good ? 'ax-notice--ok' : ((r.status === 'rolled_back' || r.status === 'cancelled' || r.status === 'closed') ? 'ax-notice--warn' : 'ax-notice--danger'));
    // no role=status: the log above (a live region) reads the run's last line out already (ux-4 P3-13)
    var ns = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('class', 'ax-ic');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    var use = document.createElementNS(ns, 'use');
    var sprite = (document.querySelector('#up-page use') || {}).getAttribute ? document.querySelector('#up-page use').getAttribute('href') : '';
    use.setAttribute('href', String(sprite).replace(/#.*$/, '') + (good ? '#ai-circle-check' : '#ai-circle-alert'));
    svg.appendChild(use);
    note.appendChild(svg);
    var body = document.createElement('div');
    // a rollback that waits for the founder and a run the founder closed: the heading says it, the message follows alone
    var title = r.status === 'done' ? L.done_title : (r.status === 'rolled_back' ? (r.kind === 'rollback' ? L.done_title : L.rolled_back_title)
      : ((r.status === 'cancelled' || r.status === 'attention' || r.status === 'closed') ? '' : L.failed_title));
    var p = document.createElement('p');
    p.textContent = join2(title ? fmt(title, {v: r.to, from: r.from}) : '', msgText(r.msg));
    body.appendChild(p);
    if (r.backup && r.backup.db) {
      var b = document.createElement('p');
      b.textContent = tr(r.backup.db_partial ? 'backup_partial_at' : 'backup_at', {db: r.backup.db});
      body.appendChild(b);
    }
    if (r.status === 'attention' && r.attention && r.attention.guide) {
      // the hand procedure: the guide file lists every file; the database line only when the update changed it and its
      // tables are not back; "delete the flag" or, when the founder did that already, that visitors have the site again
      // a version without this page (a rollback through the bridge) closes the run on install.php (e2e-5 P3-3)
      var at = r.attention, flag = at.flag || 'runtime/update/maintenance.php', old = beforeUpdater(r.after);
      // while the steps name database tables nothing closes the run by itself: the founder confirms them (e2e-6 P3-3)
      [tr('attention_steps2', {guide: at.guide, folder: at.folder || '', flag: flag}),
        at.db ? tr('attention_db', {db: at.db, list: (at.db_tables || []).join(tr('list_sep', null, '; '))}) : '',
        at.flag_on === false ? tr(old ? 'attention_flag_gone_bridge' : (at.db ? 'attention_flag_gone_db' : 'attention_flag_gone'))
          : tr(old ? 'attention_end_bridge' : (at.db ? 'attention_end_db' : 'attention_end'), {flag: flag})].forEach(function (text) {
        if (!text) { return; }
        var st = document.createElement('p');
        st.textContent = text;
        body.appendChild(st);
      });
    }
    // the version left behind may predate this page (a rollback to 1.2.0 after the update bridge): its admin has no
    // Updates page, so "Reload this page" would lead to an error page; the update bridge is install.php again
    var older = beforeUpdater(r.after);
    if (older) {
      var o = document.createElement('p');
      o.textContent = tr('no_updates_page', {v: r.after});
      body.appendChild(o);
      leftBehind(r);
    }
    var again = document.createElement('p');
    again.className = 'up-result-actions';
    if (!older && r.status !== 'attention') {
      var a = document.createElement('a');
      a.href = C.urls.index;
      a.className = 'ax-btn';
      a.textContent = L.reload_page || 'Reload';
      again.appendChild(a);
    }
    if ((older || (r.status !== 'done' && r.status !== 'cancelled')) && C.urls.home) {
      // the admin opens as a whole page, never as a second admin inside this tab
      var h = document.createElement('a');
      h.href = C.urls.home;
      h.target = '_top';
      h.className = older ? 'ax-btn ax-btn--primary' : 'ax-btn ax-btn--ghost';
      h.textContent = L.admin_home || 'Admin home';
      again.appendChild(h);
    }
    body.appendChild(again);
    note.appendChild(body);
    box.appendChild(note);
    if (r.status === 'attention') {
      // the steps first, then the form: "Try the rollback again" (after the cause is fixed) and "Close this run" (after
      // the rollback was finished by hand), both with the founder password
      var form = $('up-rearm-form');
      if (form && box.parentNode) { box.parentNode.insertBefore(form, box.nextSibling); }
      showRearm(tr('attention_retry_hint'), 'retry');
      return;
    }
    focusEl($('up-run-title'));
  }
  // ---------------------------------------------------------------- health check through this browser
  // A server with one PHP worker cannot answer the requests it makes to itself, and a site behind a password, an
  // allow-list or a CDN challenge refuses them; then the run asks this page to load the home page, a list page, a detail
  // page and the admin sign-in page (with the run's probe header, which the maintenance page lets through) and to report
  // what came back. The server judges the report with its own rules.
  var probing = false;
  function probeUrl(url, p, cred) {
    var t0 = Date.now();
    return fetch(url, {credentials: cred || 'same-origin', cache: 'no-store', redirect: 'follow', headers: {'X-Javcms-Update-Probe': p.probe}})
      .then(function (r) { return r.text().then(function (t) { return {status: r.status, text: t, url: r.url, ms: Date.now() - t0}; }); },
        function () { return {status: 0, text: '', url: url, ms: Date.now() - t0}; });
  }
  function markerIn(text, p) {
    var low = String(text || '').toLowerCase();
    for (var i = 0; i < (p.markers || []).length; i++) {
      if (low.indexOf(String(p.markers[i]).toLowerCase()) >= 0) { return p.markers[i]; }
    }
    return '';
  }
  function pickLinks(html, base) {
    var out = {list: '', detail: ''}, origin;
    try { origin = new URL(base, location.href).origin; } catch (e) { return out; }
    var doc = new DOMParser().parseFromString(html || '', 'text/html');
    var detail = ['/watch/', '/detail/', 'vod/detail', '/vod/play', 'vodplay', '/video/', '/play/'];
    var list = ['/category/', '/show/', '/type/', '/label/', '/list/', 'vod/type', 'vod/show', '/tag/', '/genre/'];
    Array.prototype.some.call(doc.querySelectorAll('a[href]'), function (a) {
      var u;
      try { u = new URL(a.getAttribute('href'), new URL(base, location.href)); } catch (e) { return false; }
      if (u.origin !== origin) { return false; }
      var low = u.href.toLowerCase();
      if (!out.detail && detail.some(function (d) { return low.indexOf(d) >= 0; })) { out.detail = u.href; }
      if (!out.list && list.some(function (d) { return low.indexOf(d) >= 0; })) { out.list = u.href; }
      return out.list && out.detail;
    });
    return out;
  }
  function browserHealth(p) {
    var report = [], base = String(p.base || '/');
    var bust = function (u) { return u + (u.indexOf('?') < 0 ? '?' : '&') + 'jcupd_probe=' + Math.random().toString(36).slice(2, 8); };
    $('up-now').textContent = tr('checking_site');
    return probeUrl(bust(base), p).then(function (home) {
      report.push({name: 'home', status: home.status, marker: home.status === 200 ? markerIn(home.text, p) : '', path: '/', ms: home.ms});
      var links = home.status === 200 ? pickLinks(home.text, base) : {list: '', detail: ''};
      var chain = Promise.resolve();
      ['list', 'detail'].forEach(function (kind) {
        chain = chain.then(function () {
          if (!links[kind]) { report.push({name: kind, skipped: 1, status: 0, path: ''}); return null; }
          return probeUrl(bust(links[kind]), p).then(function (r) {
            report.push({name: kind, status: r.status, marker: r.status === 200 ? markerIn(r.text, p) : '', path: links[kind].replace(/^https?:\/\/[^/]+/, ''), ms: r.ms});
          });
        });
      });
      return chain;
    }).then(function () {
      // the sign-in page as a stranger sees it; with this browser's credentials when a password protects the admin
      return probeUrl(base + p.admin + '/admin/index/login.html', p, 'omit').then(function (r) {
        return r.status === 401 || r.status === 403 ? probeUrl(base + p.admin + '/admin/index/login.html', p) : r;
      }).then(function (r) {
        report.push({name: 'admin', status: r.status, marker: r.status === 200 ? markerIn(r.text, p) : '', form: r.text.indexOf('admin_name') >= 0 ? 1 : 0, path: p.admin, ms: r.ms});
      });
    }).then(function () {
      var body = new URLSearchParams();
      body.append('report', JSON.stringify(report));
      return fetch(runnerUrl + 'health', {method: 'POST', credentials: 'same-origin', body: body, headers: {'X-Javcms-Update-Token': token, 'Accept': 'application/json'}})
        .then(function (r) { return r.text(); });
    });
  }

  function loop() {
    if (!running) { return; }
    if (cancelling) {
      // a cancel is on its way: no new step meanwhile (the cancel needs the step lock), check again shortly
      setTimeout(loop, 300);
      return;
    }
    var body = new URLSearchParams();
    body.append('seq', String(seq));
    var ctl = window.AbortController ? new AbortController() : null;
    var timer = ctl ? setTimeout(function () { ctl.abort(); }, 75000) : null;
    fetch(runnerUrl + 'step', {method: 'POST', credentials: 'same-origin', body: body, signal: ctl ? ctl.signal : undefined,
      headers: {'X-Javcms-Update-Token': token, 'Accept': 'application/json'}})
      .then(function (resp) {
        if (timer) { clearTimeout(timer); }
        var type = resp.headers.get('Content-Type') || '';
        if (type.indexOf('json') < 0) { var nj = new Error('not the runner'); nj.notRunner = true; nj.status = resp.status; throw nj; }
        if (resp.status === 403) { return resp.json().then(function (j) { var e = new Error(j.error || 'token'); e.token = true; throw e; }); }
        if (resp.status >= 500) { var se = new Error('step error'); se.status = resp.status; throw se; }
        return resp.json();
      })
      .then(function (r) {
        netFails = 0;
        if (r.log) { addLog(r.log); }
        if (typeof r.seq === 'number') { seq = r.seq; }
        if (r.ok === false && r.status === 'none') {
          running = false;
          try { sessionStorage.removeItem(KEY); } catch (e) { /* ignore */ }
          setTimeout(function () { location.href = C.urls.index; }, 800);
          return;
        }
        render(r);
        if (r.status === 'running' && r.health_probe && !probing) {
          probing = true;
          browserHealth(r.health_probe).then(function () { probing = false; setTimeout(loop, 200); },
            function () { probing = false; setTimeout(loop, 1000); });
          return;
        }
        if (r.status === 'running') {
          setTimeout(loop, Math.max(150, r.wait || 0));
        } else {
          finish(r);
        }
      })
      .catch(function (e) {
        if (timer) { clearTimeout(timer); }
        if (e && e.token) {
          running = false;
          showRearm(L.token_lost || e.message);
          return;
        }
        netFails++;
        if (e && e.notRunner) {
          // a proxy error page also lands here: the two entries take turns, with the usual pause
          runnerSwitch();
        }
        // a step that died (PHP time limit, crash: HTTP 5xx) or a dropped connection: the same step is asked again
        $('up-now').textContent = fmt(e && e.status >= 500 ? (L.step_error || '') : (L.network_retry || ''), {n: netFails});
        setTimeout(loop, Math.min(15000, (e && e.notRunner ? 300 : 1000) * netFails));
      });
  }
  /**
   * Cancel: the page stops sending steps while the request is out (the server waits for the step that runs now, up to
   * about 10 s). Done: the page reloads with the message. Pending (a longer step of another process holds on): the page
   * goes on, and the next answer of the run is "cancelled". Too late (files are being replaced): the run goes on.
   */
  function cancelRun(btn) {
    confirmBox(L.cancel_confirm || '', false, L.cancel_yes || '', L.cancel_no || '').then(function (yes) {
      if (!yes) { focusEl(btn); return; }
      busy(btn, true);
      cancelling = true;
      $('up-now').textContent = tr('cancelling');
      post(C.urls.cancel, {}, 60000).then(function (j) {
        busy(btn, false);
        cancelling = false;
        if (j.code === 1 && !(j.data && j.data.pending)) {
          running = false;
          try { sessionStorage.removeItem(KEY); } catch (e) { /* ignore */ }
          flash(j.msg || L.cancelled || '', 'ok');
          location.href = C.urls.index;
          return;
        }
        $('up-now').textContent = j.msg || L.failed || '';
        if (j.code === 1) {
          $('up-cancel').hidden = true;
          // this page does not drive the run (its tab was closed, or another process drives it): nobody may send the
          // step that would honour the request, so the page asks again until the step lock is free
          if (!running) { setTimeout(function () { cancelFollow(1); }, 3000); }
        }
        if (running) { setTimeout(loop, 200); }
      }, function () { busy(btn, false); cancelling = false; $('up-now').textContent = L.failed || ''; if (running) { setTimeout(loop, 500); } });
    });
  }
  /**
   * After "pending" on a page that does not drive the run: the cancel is asked again every few seconds (each request
   * waits for the step lock and cancels at once when it is free, or finds the run already cancelled by the step that
   * honoured it) - at most 12 times; a page that takes the run over (password) stops asking, its own next step honours
   * the request. A refusal (too late, ended) reloads the page so it shows what the run did.
   */
  function cancelFollow(n) {
    if (running) { return; }
    if (n > 12) { $('up-cancel').hidden = false; return; }
    post(C.urls.cancel, {}, 60000).then(function (j) {
      if (running) { return; }
      if (j.code === 1 && !(j.data && j.data.pending)) {
        try { sessionStorage.removeItem(KEY); } catch (e) { /* ignore */ }
        flash(j.msg || L.cancelled || '', 'ok');
        location.href = C.urls.index;
        return;
      }
      if (j.code === 1) { setTimeout(function () { cancelFollow(n + 1); }, 3000); return; }
      $('up-now').textContent = j.msg || L.failed || '';
      setTimeout(function () { location.href = C.urls.index; }, 3000);
    }, function () { setTimeout(function () { cancelFollow(n + 1); }, 5000); });
  }
  /**
   * $act: 'continue' (a run nobody drives) or 'retry' (a rollback that waits for the founder: with "Close this run").
   * $text: the form's own hint.
   */
  function showRearm(text, act) {
    var f = $('up-rearm-form');
    var retry = act === 'retry';
    f.hidden = false;
    f.setAttribute('data-act', retry ? 'retry' : 'continue');
    var b = f.querySelector('button[type="submit"]');
    if (b) { b.textContent = retry ? tr('attention_retry_btn') : tr('continue_btn'); }
    if ($('up-rearm-hint')) { $('up-rearm-hint').textContent = text || (retry ? tr('attention_retry_hint') : tr('continue_run')); }
    if ($('up-close-row')) { $('up-close-row').hidden = !retry; $('up-close-btn').hidden = !retry; $('up-close-ok').checked = false; }
    if (!retry) { $('up-now').textContent = text || ''; }
    syncRetry();
    focusEl($('up-rearm-password'));
  }
  /**
   * "I finished the rollback by hand" ticked: "Try the rollback again" is off (a click on it would contradict the tick) and
   * Enter in the password field closes the run; unticked, both try the rollback again (ux-6 CLICK-1: a click on "Try the
   * rollback again" once closed the run while the box was ticked).
   */
  function retryOff() {
    var f = $('up-rearm-form');
    return !!f && f.getAttribute('data-act') === 'retry' && !!$('up-close-row') && !$('up-close-row').hidden && $('up-close-ok').checked;
  }
  function syncRetry() {
    var f = $('up-rearm-form'), b = f ? f.querySelector('button[type="submit"]') : null;
    if (!b || isBusy(b)) { return; }
    if (retryOff()) { b.setAttribute('aria-disabled', 'true'); } else { b.removeAttribute('aria-disabled'); }
  }
  /**
   * "retry" / "close" of a rollback that waits for the founder: through this page's controller (the framework checked the
   * founder's sign-in, so it works on every host), else - the admin pages turned into the run's own page meanwhile - the
   * run's own entry. Resolves with the run's answer as the run's own entry gives it, or {ok: false, k, error}.
   */
  function attentionPost(act, pwd) {
    var data = {password: pwd, seq: String(seq)};
    return post(C.urls[act], data, 60000).then(function (j) {
      if (j && j.code === 1 && j.data) { return j.data; }
      return {ok: false, k: (j && j.data && j.data.k) || '', error: (j && j.msg) || L.failed};
    }, function () {
      return post(runnerUrl + act, data, 60000, {'X-Javcms-Update-Continue': '1'});
    });
  }
  // "Close this run": the founder finished the rollback by hand (the tick confirms it), then the run ends as closed
  var closeBtn = $('up-close-btn');
  if (closeBtn) {
    closeBtn.addEventListener('click', function () {
      var pwd = $('up-rearm-password');
      if (isBusy(closeBtn)) { return; }
      if (!$('up-close-ok').checked) { $('up-now').textContent = tr('attention_close_need_tick'); focusEl($('up-close-ok')); return; }
      if (!pwd.value) { $('up-now').textContent = L.password_needed || ''; focusEl(pwd); return; }
      busy(closeBtn, true);
      attentionPost('close', pwd.value).then(function (j) {
        pwd.value = '';
        busy(closeBtn, false);
        if (!j || j.status !== 'closed') { $('up-now').textContent = j && j.k ? tr(j.k, null, j.error) : (j && j.error) || L.failed; focusEl(pwd); return; }
        $('up-rearm-form').hidden = true;
        if (j.log) { addLog(j.log); }
        if (typeof j.seq === 'number') { seq = j.seq; }
        finish(j);
      }, function () { busy(closeBtn, false); $('up-now').textContent = L.failed || ''; });
    });
  }
  /**
   * Another page (or the command line) drives the run right now: this tab follows it without the password form and
   * reloads every few seconds until the run is over (the Updates page then shows how it ended).
   */
  var followTimer = null;
  function followDriven() {
    if ($('up-rearm-form')) { $('up-rearm-form').hidden = true; }
    $('up-cancel').hidden = true;
    $('up-now').textContent = tr('run_driven_elsewhere');
    if (!followTimer) { followTimer = setTimeout(function () { location.reload(); }, 5000); }
  }
  var rearmForm = $('up-rearm-form');
  if (rearmForm) {
    // Enter in the password field: with "I finished the rollback by hand" ticked the founder means "Close this run", never
    // "Try the rollback again" (which switches the maintenance page on again) - ux-5 ENTER-1; handled here, before the
    // form's own submission, which reports the default button ("Try the rollback again") either way
    $('up-rearm-password').addEventListener('keydown', function (ev) {
      if (ev.key !== 'Enter' || ev.isComposing || ev.keyCode === 229) { return; }
      if (retryOff() && closeBtn && !closeBtn.hidden) { ev.preventDefault(); closeBtn.click(); }
    });
    if ($('up-close-ok')) {
      $('up-close-ok').addEventListener('change', function () {
        syncRetry();
        $('up-now').textContent = retryOff() ? tr('attention_retry_off') : '';
      });
    }
    rearmForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var pwd = $('up-rearm-password'), btn = rearmForm.querySelector('button[type="submit"]');
      if (isBusy(btn)) { return; }
      // "Try the rollback again" pressed while the box is ticked (the keyboard on the button: the mouse cannot press it
      // then): neither a retry nor a close - the founder unticks the box or presses "Close this run" (ux-6 CLICK-1)
      if (retryOff()) { $('up-now').textContent = tr('attention_retry_off'); focusEl($('up-close-ok')); return; }
      if (!pwd.value) { focusEl(pwd); return; }
      busy(btn, true);
      var act = rearmForm.getAttribute('data-act') === 'retry' ? 'retry' : 'continue';
      // "retry" through this page's controller first; "continue" through the run's own entry (the admin pages may be the
      // run's progress page while files are replaced)
      var req = act === 'retry' ? attentionPost('retry', pwd.value)
        : post(runnerUrl + act, {password: pwd.value, seq: String(seq)}, 60000, {'X-Javcms-Update-Continue': '1'});
      req.then(function (j) {
        pwd.value = '';
        busy(btn, false);
        syncRetry();
        if (j && j.k === 'continue_not_now') { followDriven(); return; }
        if (!j || !j.token) { $('up-now').textContent = j && j.k ? tr(j.k, null, j.error) : (j && j.error) || L.failed; focusEl(pwd); return; }
        rearmForm.hidden = true;
        rearmForm.removeAttribute('data-act');
        $('up-result').hidden = true;
        token = j.token;
        runId = j.run;
        if (j.log) { addLog(j.log); }
        if (typeof j.seq === 'number') { seq = j.seq; }
        try { sessionStorage.setItem(KEY, JSON.stringify({run: runId, token: token})); } catch (e) { /* ignore */ }
        running = true;
        $('up-log-dl').href = C.urls.log + '?run=' + encodeURIComponent(runId);
        $('up-log-dl').hidden = false;
        $('up-keep-open').hidden = false;
        render(j);
        // the hidden password field had the focus: the run's heading takes it (as when a run starts)
        focusEl($('up-run-title'));
        loop();
      }, function () { busy(btn, false); syncRetry(); $('up-now').textContent = L.failed; });
    });
  }
  window.addEventListener('beforeunload', function (ev) {
    if (running) { ev.preventDefault(); ev.returnValue = L.leave_warning || ''; return ev.returnValue; }
  });

  // ---------------------------------------------------------------- a run in progress when the page opens
  if (C.run && C.run.status === 'running') {
    runKind = C.run.kind;
    runTo = C.run.to;
    render(C.run);
    var saved = null;
    try { saved = JSON.parse(sessionStorage.getItem(KEY) || 'null'); } catch (e) { saved = null; }
    if (saved && saved.run === C.run.run && saved.token) {
      token = saved.token;
      runId = saved.run;
      running = true;
      $('up-log-dl').href = C.urls.log + '?run=' + encodeURIComponent(runId);
      $('up-log-dl').hidden = false;
      loop();
    } else if (C.run_driven) {
      followDriven();
    } else {
      showRearm(L.continue_run || '');
    }
  } else if (C.run && C.run.status === 'attention') {
    // a rollback that waits for the founder (normally shown by the run's own page while the admin pages are held)
    runKind = C.run.kind;
    finish(C.run);
  }
})();
