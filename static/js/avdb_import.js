/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
(function (global) {
 'use strict';
 // Texts come from window.AX_AVDB_LANG (lang keys admin/ux/tools/avdb_<slug>, printed by collect/avdb.html).
 var AXL = global.AX_AVDB_LANG && typeof global.AX_AVDB_LANG === 'object' ? global.AX_AVDB_LANG : {};
 function T(key, vars) { var text = typeof AXL[key] === 'string' ? AXL[key] : key; Object.keys(vars || {}).forEach(function (name) { text = text.split('{' + name + '}').join(String(vars[name])); }); return text; }
 function stateLabel(state) { return typeof AXL['st_' + state] === 'string' ? AXL['st_' + state] : String(state); }
 // xvid-scale lane E: texts of the provider tabs, scopes, estimate, status and clean-up (lang keys admin/ux/scale/<slug>, window.AX_SCALE_LANG).
 var AXS = global.AX_SCALE_LANG && typeof global.AX_SCALE_LANG === 'object' ? global.AX_SCALE_LANG : {};
 function S(key, vars) { var text = typeof AXS[key] === 'string' ? AXS[key] : key; Object.keys(vars || {}).forEach(function (name) { text = text.split('{' + name + '}').join(String(vars[name])); }); return text; }
 function num(n) { var v = Number(n); return Number.isFinite(v) ? v.toLocaleString() : '0'; }
 function fmtMB(mb) { var v = Number(mb); if (!Number.isFinite(v) || v < 0) return S('st_unknown'); return v < 1024 ? num(Math.round(v)) + ' MB' : (v / 1024).toLocaleString(undefined, {maximumFractionDigits: 1, minimumFractionDigits: 1}) + ' GB'; }
 function fmtMin(min) { var v = Number(min); if (!Number.isFinite(v) || v < 0) return S('st_unknown'); if (v < 10) return S('es_min', {n: Math.max(0.1, Math.round(v * 10) / 10).toLocaleString(undefined, {maximumFractionDigits: 1})}); if (v < 60) return S('es_min', {n: num(Math.round(v))}); if (v < 2880) return S('es_h', {n: (v / 60).toLocaleString(undefined, {maximumFractionDigits: 1})}); return S('es_days', {n: (v / 1440).toLocaleString(undefined, {maximumFractionDigits: 1})}); }
 // A command-line heartbeat counts when the runner was seen in the last 24 hours (brief 2.2, D4).
 function cliFresh(seenAt, now) { var s = Number(seenAt || 0); return s > 0 && (Number(now || Date.now() / 1000) - s) < 86400; }
 // xv-nolimit-E (XVID-NOLIMIT-BRIEF A1-A3): "Whole catalog" first and preselected; no size rule on this page (the web
 // runner continues by itself), no typed confirmation, and the storage target is the only stop.
 var SCOPES = ['full', 'recent_10000', 'recent_50000', 'recent_100000', 'recent_1000000', 'recent', 'category'];
 function numOr(v, d) { var n = Number(v); return v !== null && v !== undefined && v !== '' && Number.isFinite(n) ? n : d; }
 // Free space the import may use: the database limit minus the database now, else the free disk (null = not reported).
 function spaceLeft(e) {
  var limit = numOr(e.db_limit_mb, null), now = numOr(e.db_mb_now, 0);
  if (limit !== null) return Math.max(0, limit - now);
  var free = numOr(e.free_disk_mb, numOr(e.tmp_free_mb, null));
  return free === null ? null : Math.max(0, free);
 }
 // A2: where the job stops. C19 key stop_at_videos when the server sends it, else the same rule computed here from the
 // estimate (target = 0.8 x the database limit, size per video = growth / videos). null = the whole scope fits.
 function stopAt(e) {
  if (!e) return null;
  var total = numOr(e.videos, 0), limit = numOr(e.db_limit_mb, null), target = numOr(e.target_db_mb, limit !== null ? 0.8 * limit : null);
  if (e.stop_at_videos !== undefined && e.stop_at_videos !== null) {
   var n = Math.max(0, Math.floor(numOr(e.stop_at_videos, total)));
   return n < total ? {videos: n, db_mb: target, reason: e.stop_reason || (target !== null ? 'storage_target' : 'disk')} : null;
  }
  if (e.fits !== false || limit === null || target === null || total <= 0) return null;
  var now = numOr(e.db_mb_now, 0), after = numOr(e.db_mb_after, now), per = (after - now) / total;
  if (!(per > 0)) return null;
  var stop = Math.max(0, Math.min(total, Math.floor((target - now) / per)));
  return stop < total ? {videos: stop, db_mb: target, reason: 'storage_target'} : null;
 }
 // A1: one confirmation checkbox, only for a job above 1,000,000 videos or above half of the space left (misclick guard).
 function confirmNeeded(e) {
  if (!e) return false;
  if (e.confirm_needed !== undefined) return !!e.confirm_needed;
  var videos = numOr(e.videos, 0), grow = Math.max(0, numOr(e.db_mb_after, 0) - numOr(e.db_mb_now, 0)), left = spaceLeft(e);
  return videos > 1000000 || (left !== null && grow > 0.5 * left);
 }
 // The page starts web jobs; a large job goes to cron when cron runs on this site (faster, no open tab needed).
 function runnerFor(e, now) { return (e.cli_recommended || e.needs_cli) && cliFresh(e.cli_seen_at, now) ? 'cli' : 'web'; }
 // A3 times: tab open = the estimate; tab closed = one 8 s step per 30 s while the site has visitors; cron = 4 of 5 minutes.
 function importTimes(e) {
  var open = numOr(e.import_minutes_here, null);
  return {open: open, closed: numOr(e.import_minutes_closed, open === null ? null : open * 30 / 8), cron: numOr(e.import_minutes_cron, open === null ? null : open * 5 / 4)};
 }
 // Pure decision used by the page and by the tests: what the start button may do for one C7 estimate. Never refuses
 // for size: a scope larger than the space left starts too and stops cleanly at the storage target (A2).
 function startDecision(e, opts) {
  opts = opts || {};
  if (!e) return {action: 'none', reason: 'loading'};
  var runner = runnerFor(e, opts.now);
  if (confirmNeeded(e) && !opts.confirmed) return {action: 'confirm', reason: 'checkbox', runner: runner};
  return {action: 'start', runner: runner};
 }
 function Selection() { this.items = new Map(); }
 Selection.prototype.toggle = function (row, checked) {
  var id = Number(row.vod_id);
  if (!Number.isSafeInteger(id) || id < 1 || id > 2147483647) throw new Error(T('invalid_id'));
  if (checked) { if (!this.items.has(id) && this.items.size >= 1000) throw new Error(T('select_max')); this.items.set(id, row); }
  else this.items.delete(id);
 };
 Selection.prototype.ids = function () { return Array.from(this.items.keys()); };
 Selection.prototype.rows = function () { return Array.from(this.items.values()); };
 Selection.prototype.clear = function () { this.items.clear(); };
 function readFilters(values) {
  var out = {};
  ['wd','tag','code','t','actor','director','writer','category','year','h','isend','from','sort_direction','pagesize'].forEach(function (key) {
   if (values[key] !== undefined && values[key] !== null && String(values[key]).trim() !== '') out[key] = String(values[key]).trim();
  });
  return out;
 }
 function jobProgress(job) {
  if (['complete','completed','rolled_back','rollback_conflict'].includes(job.state)) return 100;
  if (Number(job.max_items) === 0) return null;
  return Math.min(99, Math.max(0, Math.floor(Number(job.processed || 0) / Math.max(1, Number(job.max_items || 1)) * 100)));
 }
 var exports = {Selection: Selection, readFilters: readFilters, jobProgress: jobProgress, startDecision: startDecision, cliFresh: cliFresh, fmtMB: fmtMB, fmtMin: fmtMin, SCOPES: SCOPES, stopAt: stopAt, confirmNeeded: confirmNeeded, importTimes: importTimes, runnerFor: runnerFor};
 if (typeof module !== 'undefined' && module.exports) module.exports = exports;
 if (!global.document || !global.jQuery) return;
 global.jQuery(function () {
  var $ = global.jQuery, root = document.getElementById('avdb-workspace');
  if (!root) return;
  var $root = $(root), selected = new Selection(), capabilities = null, previewRows = [], page = 1, filters = {}, more = false;
  var categoryMap = Object.create(null), playerMap = Object.create(null), playerOverrides = Object.create(null), playerAutoDefault = Object.create(null), defaultPlayerInitialized = false, lastSelectedCount = 0, existingLinks = Object.create(null), presets = [], job = null, timer = null, automatic = false, mutationBusy = false, previewSequence = 0, previewReady = false, previewBusy = false;
  var presetKey = 'javcms-avdb-import-presets-v1', mutationTail = Promise.resolve(), retryTimer = null, liveTimer = null, liveBusy = false, renderVersion = 0, itemPage = 1, itemBusy = false, itemSequence = 0, lastItemsCheck = 0, lastItemsSignature = null, pendingItemsSignature = null, jobSelection = 0;
  var labels = {}; try { labels = JSON.parse(root.dataset.i18n || '{}'); } catch (error) {}
  function label(key, fallback) { return typeof labels[key] === 'string' ? labels[key] : fallback; }
  function itemSignature(data) { return JSON.stringify([data.id,data.state,data.queued,data.processed,data.created,data.updated,data.skipped,data.failed,data.phase === 'rollback' ? data.updated_at : null]); }
  function node(tag, text, attrs) { return $('<' + tag + '>').text(text == null ? '' : String(text)).attr(attrs || {}); }
  function message(text, error) { $('#avdb-message').text(text).attr('data-error', error ? 'true' : 'false').prop('hidden', !text); }
  // P3-4: a failed load says so in the table (not "Connecting and loading the first page…" forever) and offers Retry.
  function loadFailed(retry) {
   previewRows = []; var body = $('#avdb-results').empty(), cell = node('td', body.attr('data-failed') || T('request_failed'), {colspan: 5});
   cell.append(document.createTextNode(' '), node('button', T('retry'), {type: 'button', class: 'ax-btn ax-btn--sm avdb-retry-load'}).on('click', retry));
   body.append(node('tr').append(cell)); $('#avdb-next').prop('disabled', true); $('#avdb-select-page').prop('checked', false).prop('indeterminate', false);
  }
  function values(form) { var result = {}; $(form).serializeArray().forEach(function (item) { result[item.name] = item.value; }); return result; }
  async function request(action, data, method) {
   method = method || 'GET';
   if (!root.dataset[action]) throw new Error(T('action_missing'));
   var releaseMutation;
   if (method === 'POST') {
    var preceding = mutationTail;
    mutationTail = new Promise(function(resolve){releaseMutation=resolve;});
    await preceding;
    mutationBusy = true;
    data = Object.assign({}, data, {__token__: $root.find('input[name="__token__"]').val()});
   }
   try {
    var response = await $.ajax({url: root.dataset[action], method: method, data: data || {}, dataType: 'json', timeout: 45000});
    if (response.data && response.data.__token__) $root.find('input[name="__token__"]').val(response.data.__token__);
    if (Number(response.code) !== 1) { var failure = new Error(response.msg || T('request_failed')); failure.retryAfter = Number(response.data && response.data.retry_after || 0); throw failure; }
    return response.data;
   } catch (error) {
    if (error.message) throw error;
    var unreachable = new Error(error.statusText === 'timeout' ? T('conn_timeout') : T('unreachable')); unreachable.status = Number(error.status || 0); throw unreachable;
   } finally { if (method === 'POST') { mutationBusy = false; releaseMutation(); } }
  }
  function panel(name) {
   closeSuggestions();
   ['find','review','scope','run'].forEach(function (key) { $(key === 'scope' ? '#xv-scope' : '#avdb-' + key).prop('hidden', key !== name); $root.find('.avdb-steps [data-panel="' + key + '"]').attr('aria-current', key === name ? 'step' : null); });
   if (name === 'scope' && global.AxImportScale) global.AxImportScale.openScope();
   if (name === 'find' || name === 'review') { $('#avdb-' + name + '-filter-host').append($('#avdb-filter-form')); $('#avdb-search').text(name === 'review' ? label('apply_preview','Apply filters and preview') : T('preview_videos')).toggleClass('ax-btn--primary', name !== 'review'); }
   if (name === 'review') { renderMappings(); summary(); }
   $('#avdb-' + name + '-title').attr('tabindex', '-1').trigger('focus');
  }
  function selectionChanged() {
   var count = selected.ids().length, scope = values('#avdb-options-form').mode;
   if (count !== lastSelectedCount) { if (count > 0 && scope === 'all') $('#avdb-options-form [name=mode][value=selected]').prop('checked',true); else if (!count && scope === 'selected') $('#avdb-options-form [name=mode][value=all]').prop('checked',true); lastSelectedCount = count; }
   $('#avdb-selected-count').text(T('n_selected', {n: selected.ids().length})); $('#avdb-review-selection').text('(' + selected.ids().length + ')');
   var checked = previewRows.filter(function (row) { return selected.items.has(Number(row.vod_id)); }).length;
   $('#avdb-select-page').prop('checked', previewRows.length > 0 && checked === previewRows.length).prop('indeterminate', checked > 0 && checked < previewRows.length);
   summary();
  }
  function renderRows() {
   var body = $('#avdb-results').empty();
   if (!previewRows.length) body.append(node('tr').append(node('td', T('no_match'), {colspan: 5})));
   previewRows.forEach(function (row) {
    var tr = $('<tr>'), checkbox = $('<input>', {type: 'checkbox', 'aria-label': T('select_video', {name: row.vod_name})}).prop('checked', selected.items.has(Number(row.vod_id)));
    checkbox.on('change', function () { try { selected.toggle(row, this.checked); selectionChanged(); } catch (e) { this.checked = false; message(e.message, true); } });
    tr.append($('<td>').append(checkbox));
    tr.append($('<td>').append(node('div', row.vod_name, {class: 'avdb-video-title'}), node('div', T('avdb_id', {id: row.vod_id}) + ' · ' + (row.vod_remarks || row.vod_sub || T('no_code')), {class: 'avdb-video-info'})));
    tr.append(node('td', (row.type_name || T('category_n', {id: row.type_id})) + ' / ' + (row.vod_year || T('unknown_year'))));
    var state = $('<td>');
    if (row.existing) state.text(T('imported_id', {id: row.existing.vod_id}));
    else if (row.existing_candidate && row.existing_candidate.ambiguous) state.text(T('multi_match'));
    else if (row.existing_candidate) {
     var link = $('<input>', {type:'checkbox'}).prop('checked', !!existingLinks[row.vod_id]);
     link.on('change', function () {
      try { if (this.checked) { selected.toggle(row, true); existingLinks[row.vod_id] = row.existing_candidate.vod_id; checkbox.prop('checked',true); } else delete existingLinks[row.vod_id]; }
      catch (error) { this.checked = false; message(error.message, true); }
      selectionChanged();
     });
     state.append($('<label>', {class:'avdb-choice'}).append(link, document.createTextNode(T('link_to', {id: row.existing_candidate.vod_id}))), node('small',row.existing_candidate.vod_name));
    } else state.text(T('new_video'));
    tr.append(state);
    var details = node('button', T('details'), {type: 'button', class: 'ax-btn ax-btn--sm'}).on('click', function () {
     $('#avdb-detail-title').text(row.vod_name); var container = $('#avdb-detail-body').empty();
     [[T('f_code'),row.vod_remarks || row.vod_sub],[T('f_avdb_id'),row.vod_id],[T('f_category'),row.type_name || row.type_id],[T('f_actors'),row.vod_actor],[T('f_director'),row.vod_director],[T('f_studio'),row.vod_writer],[T('f_year'),row.vod_year],[T('f_media_lang'),row.vod_lang],[T('f_sources'),row.vod_play_from]].forEach(function (pair) { container.append(node('p',pair[0] + ': ' + (pair[1] || T('not_supplied')))); });
     var description = String(row.vod_content || row.vod_blurb || '').replace(/<(script|style)\b[^>]*>[\s\S]*?<\/\1>/gi,'').replace(/<[^>]*>/g,'');
     container.append(node('p', description)); document.getElementById('avdb-detail-dialog').showModal();
    });
    tr.append($('<td>').append(details)); body.append(tr);
   });
   selectionChanged();
  }
  async function loadPreview(nextPage, override) {
   var sequence = ++previewSequence, requestedFilters = override || readFilters(values('#avdb-filter-form')); previewBusy = true; previewReady = false; summary();
   $('#avdb-find').attr('aria-busy', 'true'); $('#avdb-search').prop('disabled', true); if (xvFilterNow()) message(T('loading_preview'));
   try {
    var response = await request('preview', {filters: Object.assign({}, requestedFilters, {pg: nextPage})});
    if (sequence !== previewSequence) return;
    filters = readFilters(requestedFilters); previewReady = true;
    previewRows = response.items || []; page = Number(response.page || nextPage); more = !!response.has_more;
    $('#avdb-page-number').text(T('page_n', {n: page})); $('#avdb-page-input').val(page); $('#avdb-prev').prop('disabled', page <= 1); $('#avdb-next').prop('disabled', !more);
    $('#avdb-result-count').text(T('page_count', {n: previewRows.length}) + ' · ' + T(response.total_estimated ? 'matches_est' : 'matches', {n: Number(response.total || 0).toLocaleString()}));
    $('#avdb-review-preview-count').text($('#avdb-result-count').text()); renderRows(); renderMappings(); if (xvFilterNow()) message(T('preview_ready'));
   } catch (e) { if (sequence !== previewSequence) return; message(e.message + (e.retryAfter ? ' ' + T('try_after', {n: e.retryAfter}) : ''), true); loadFailed(function () { loadPreview(nextPage, override); }); }
   finally { if (sequence === previewSequence) { previewBusy = false; $('#avdb-find').attr('aria-busy', 'false'); $('#avdb-search').prop('disabled', false); summary(); } }
  }
  function renderMappings() {
   if (!capabilities) return;
   var form = values('#avdb-options-form'), rows = form.mode === 'selected' ? selected.rows() : previewRows;
   var required = new Set(form.mode !== 'selected' && filters.t ? [String(filters.t)] : rows.map(function (r) {return String(r.type_id);}));
   if (form.mode !== 'selected' && !filters.t) (capabilities.provider_categories || []).forEach(function (c) { required.add(String(c.type_id)); });
   var maps = $('#avdb-category-maps').empty();
   (capabilities.provider_categories || []).filter(function (c) {return required.has(String(c.type_id));}).forEach(function (category) {
    // r5 repair (beginner r5 P1-2): a hidden category with the same name (and no visible one) is reused, still hidden.
    var lname = String(category.type_name || '').trim().toLowerCase(), locals = capabilities.local_categories || [];
    var visibleMatch = locals.some(function (l) { return String(l.type_name || '').trim().toLowerCase() === lname && String(l.type_status) === '1'; });
    var hiddenMatch = !visibleMatch && locals.some(function (l) { return String(l.type_name || '').trim().toLowerCase() === lname && String(l.type_status) !== '1'; });
    var auto = $('#avdb-create-categories').prop('checked') ? (hiddenMatch ? T('cat_hidden', {name: category.type_name}) : T('match_or_create')) : T('choose_category');
    var key = String(category.type_id), select = $('<select>').attr('data-remote-category', key).append(node('option', auto, {value:''}));
    locals.forEach(function (local) {
     select.append(node('option', String(local.type_status) === '1' || local.type_status === undefined ? local.type_name : T('cat_hidden_opt', {name: local.type_name}), {value: local.type_id}));
    });
    select.val(categoryMap[key] || '').on('change', function () { categoryMap[key] = this.value; summary(); }); maps.append(node('label',category.type_name).append(select));
   });
   if (!maps.children().length) maps.append(node('p',T('select_or_preview')));
   var sourceSet = new Set(); rows.forEach(function (row) {String(row.vod_play_from || '').split('$$$').filter(Boolean).forEach(function(s){sourceSet.add(s);});});
   var players = $('#avdb-player-maps').empty();
   sourceSet.forEach(function (source) {
    var select = $('<select>').attr('data-remote-source',source).append(node('option',T('choose_player'),{value:''}));
    (capabilities.players || []).forEach(function (player) { var key = player.key || player.from || player.id; select.append(node('option', (player.name || player.show || key) + ' (' + key + ')', {value: key})); if (!Object.prototype.hasOwnProperty.call(playerMap,source) && key === source) playerMap[source] = key; });
    if (!Object.prototype.hasOwnProperty.call(playerMap,source)) { playerMap[source] = $('#avdb-default-player').val() || ''; playerAutoDefault[source] = true; }
    select.val(playerMap[source] || '').on('change', function () {playerMap[source]=this.value;playerOverrides[source]=true;summary();}); players.append(node('label',source).append(select));
   });
   if (!players.children().length) players.append(node('p',T('preview_for_sources')));
  }
  function importOptions() {
   var form = values('#avdb-options-form'), locales = ['en'], links = Object.create(null), categories = Object.create(null), players = Object.create(null);
   if (form.language_mode === 'multi') $('#avdb-language-options input:checked').each(function () {locales.push(this.value);});
   Object.keys(categoryMap).forEach(function(k){if(categoryMap[k])categories[k]=categoryMap[k];}); Object.keys(playerMap).forEach(function(k){if(playerMap[k])players[k]=playerMap[k];});
   selected.ids().forEach(function(id){if(existingLinks[id])links[id]=existingLinks[id];});
   // r5 repair (beginner r5 P2-5): the preview shows 50 rows per page; importing all matches still reads the native
   // 1000-row pages (as before), so the import and automatic imports do not get slower.
   var importFilters = Object.assign({}, filters); if (form.mode === 'all') importFilters.pagesize = '1000';
   return {mode:form.mode,ids:form.mode==='selected'?selected.ids():[],filters:importFilters,start_page:Number(form.start_page),end_page:Number(form.end_page),max_items:form.mode==='all' && $('#avdb-unlimited').prop('checked')?0:Number(form.max_items),default_player:form.default_player,locales:locales,category_map:categories,create_categories:$('#avdb-create-categories').prop('checked')?1:0,player_map:players,existing_links:links,existing:form.existing,publish:Number(form.publish),download_images:$('#avdb-download-images').prop('checked')?1:0,update_fields:$('#avdb-update-fields input:checked').map(function(){return this.value;}).get()};
  }
  function currentPreviewMatches() { return previewReady && !previewBusy && JSON.stringify(readFilters(values('#avdb-filter-form'))) === JSON.stringify(readFilters(filters)); }
  var schedule = null, scheduleBusy = false, scheduleDraft = false;
  function scheduleControls() {
   var valid = $('#avdb-start').prop('disabled') === false && values('#avdb-options-form').mode === 'all' && currentPreviewMatches();
   $('#avdb-schedule-save').prop('disabled', scheduleBusy || !$('#avdb-schedule-enabled').prop('checked') || !valid);
   $('#avdb-schedule-enabled,#avdb-schedule-interval,#avdb-schedule-refresh,#avdb-schedule-pause,#avdb-schedule-resume').prop('disabled',scheduleBusy);
  }
  function renderSchedule(data, replaceDraft) {
   schedule = data || {};
   var savedInterval = Number(schedule.interval_minutes || 1440);
   if (Number.isSafeInteger(savedInterval) && savedInterval > 0 && !$('#avdb-schedule-interval option').filter(function(){return Number(this.value)===savedInterval;}).length) {
    $('#avdb-schedule-interval').append(node('option',label('schedule_minutes','{count} minutes').replace('{count}',String(savedInterval)),{value:savedInterval}));
   }
   if (replaceDraft || !scheduleDraft) {
    $('#avdb-schedule-enabled').prop('checked',!!schedule.enabled);
    $('#avdb-schedule-interval').val(String(schedule.interval_minutes || 1440));
    scheduleDraft = false;
   }
   var state = !schedule.enabled ? 'off' : schedule.state || 'idle';
   var text = label('schedule_' + state, state);
   if (Number(schedule.next_run_at)>0 && schedule.enabled && schedule.state!=='paused') text += ' · ' + label('schedule_next','Next run') + ': ' + new Date(Number(schedule.next_run_at)*1000).toLocaleString();
   if (schedule.job_id) text += ' · ' + label('schedule_job','Active import') + ': ' + String(schedule.job_id).slice(0,8);
   $('#avdb-schedule-status').text(text);
   var saved = schedule.options && schedule.options.mode === 'all' ? schedule.options : null;
   var intervalText = $('#avdb-schedule-interval option[value="'+Number(schedule.interval_minutes)+'"]').text();
   var details = saved ? [label('schedule_interval','Repeat every')+': '+intervalText, label('all_scope','All matching videos'), Object.keys(saved.filters || {}).map(function(key){return String(saved.filters[key]);}).join(' · '), label('schedule_limit','Item limit')+': '+(Number(saved.max_items)===0?label('schedule_unlimited','No limit'):Number(saved.max_items)), label('schedule_languages','Languages')+': '+(saved.locales || ['en']).join(', '), label(saved.existing,saved.existing || ''), saved.publish?label('schedule_published','Published'):label('schedule_drafts','Drafts')].filter(Boolean).join(' · ') : '';
   $('#avdb-schedule-saved').text(saved ? label('schedule_saved','Saved settings') + ': ' + details : label('schedule_none','No recurring import settings saved.'));
   $('#avdb-schedule-error').text(schedule.error || '').attr('title', schedule.error_raw && schedule.error_raw !== schedule.error ? schedule.error_raw : null).prop('hidden',!schedule.error);
   $('#avdb-schedule-pause').prop('hidden',!schedule.enabled || schedule.state==='paused');
   $('#avdb-schedule-resume').prop('hidden',!schedule.enabled || schedule.state!=='paused');
   scheduleControls();
  }
  async function scheduleAction(action, data) {
   scheduleBusy=true;scheduleControls();
   try {renderSchedule(await request(action,data || {},action==='scheduleStatus'?'GET':'POST'),action!=='scheduleStatus');}
   catch(error) {
    if(action==='scheduleStatus' && !schedule) $('#avdb-schedule-status').text(label('schedule_unavailable','Managed scheduling unavailable.'));
    $('#avdb-schedule-error').text(label('schedule_unavailable','Managed scheduling unavailable.')+' '+error.message).prop('hidden',false);
   }
   finally {scheduleBusy=false;scheduleControls();}
  }
  $('#avdb-schedule-enabled').on('change',function(){
   scheduleDraft=true;
   if(!this.checked) scheduleAction('scheduleOff');
   else {$('#avdb-schedule-review-note').text(label('schedule_review','Save to enable with the reviewed all-matching import settings. Editing filters or options does not change saved settings.'));scheduleControls();}
  });
  $('#avdb-schedule-interval').on('change',function(){scheduleDraft=true;scheduleControls();});
  $('#avdb-schedule-form').on('submit',function(event){
   event.preventDefault();var options=importOptions(), interval=Number($('#avdb-schedule-interval').val());
   if(scheduleBusy || !$('#avdb-schedule-enabled').prop('checked'))return;
   if(options.mode!=='all' || !currentPreviewMatches() || $('#avdb-start').prop('disabled')) {message(label('schedule_invalid','Apply a successful preview and review an all-matching import before saving automatic imports.'),true);return;}
   if(!Number.isSafeInteger(interval) || interval<1 || interval>10080)return;
   scheduleAction('scheduleSave',{options:options,interval_minutes:interval});
  });
  $('#avdb-schedule-refresh').on('click',function(){scheduleAction('scheduleStatus');});
  $('#avdb-schedule-pause').on('click',function(){scheduleAction('schedulePause');});
  $('#avdb-schedule-resume').on('click',function(){scheduleAction('scheduleResume');});
  function summary() {
   var currentMode = values('#avdb-options-form').mode;
   if (currentMode !== 'all') $('#avdb-unlimited').prop('checked',false);
   $('#avdb-options-form [name=max_items],#avdb-limit-preset').prop('disabled',currentMode==='all' && $('#avdb-unlimited').prop('checked'));
   var options = importOptions(), valid = !!capabilities && !previewBusy && (options.mode !== 'selected' ? currentPreviewMatches() : options.ids.length > 0);
   var labels = {wd:T('f_search'),tag:T('f_tag'),code:T('f_code'),t:T('f_category'),actor:T('f_actor'),director:T('f_director'),writer:T('f_studio'),category:T('f_genre'),year:T('f_year'),h:T('f_hours'),isend:T('f_completion'),from:T('f_source'),sort_direction:T('f_order'),pagesize:T('f_pagesize')};
   var descriptions = Object.keys(filters).map(function(key){return (labels[key] || key) + ': ' + filters[key];});
   $('#avdb-range-filter-note').text(currentPreviewMatches() ? label('filters_used','Successful preview filters') + ': ' + (descriptions.join(' · ') || T('all_videos')) : label('preview_required','Apply the current filters successfully before importing matching videos or a page range.'));
   $('#avdb-review').find('[data-remote-category],[data-remote-source]').each(function(){if(!this.value && !(this.hasAttribute('data-remote-category') && options.create_categories))valid=false;});
   $('#avdb-unlimited-option').prop('hidden',options.mode!=='all');
   $('#avdb-start').prop('disabled', !valid || mutationBusy);
   scheduleControls();
   var scope = options.mode === 'selected' ? T('n_selected_videos', {n: options.ids.length}) : options.mode === 'all' ? label('all_scope','all videos matching the previewed filters') : T('pages_range', {from: options.start_page, to: options.end_page});
   $('#avdb-import-summary').text(T('sum_import', {scope: scope}) + (options.max_items ? T('sum_limit', {n: options.max_items}) : T('sum_nolimit')) + ' ' + label('requested_languages','English + up to {count} available extra languages').replace('{count}', String(Math.max(0,options.locales.length-1))) + '. ' + T('sum_new', {v: options.publish ? T('sum_published') : T('sum_drafts')}) + ' ' + T('sum_existing', {v: label(options.existing, options.existing)}) + ' ' + T('sum_categories', {v: options.create_categories ? T('sum_cat_create') : T('sum_cat_chosen')}) + ' ' + T('sum_images', {v: options.download_images ? T('sum_img_local') : T('sum_img_remote')}));
  }
  function renderJobState(data) {
   var descriptions = {discover:T('ph_discover'),locales:label('language_phase','Checking available extra languages'),languages:label('language_phase','Checking available extra languages'),apply:T('ph_apply'),complete:T('ph_complete'),images:T('ph_images'),rollback:T('ph_rollback')};
   $('#avdb-job-status').text(stateLabel(data.state) + ' · ' + (descriptions[data.phase] || data.phase) + (data.error ? ' · '+data.error : '') + (data.stop_reason === 'storage_target' && !data.error ? ' · ' + S('job_stop_target') : '') + (data.retry_after?' · '+T('retry_after', {n: data.retry_after}):''));
   var terminal = ['complete','completed','rolled_back','rollback_conflict'].includes(data.state);
   $root.find('[data-job-action="pause"]').prop('disabled', terminal || !automatic);
   $root.find('[data-job-action="resume"]').prop('disabled', terminal || automatic || !!data.retry_after);
   $root.find('[data-job-action="cancel"]').prop('disabled', terminal);
   $root.find('[data-job-action="retry"]').prop('disabled', !data.failed || automatic || !!data.retry_after);
   $('#avdb-undo').prop('disabled', automatic || ['rolled_back','rollback_conflict'].includes(data.state));
  }
  function renderJob(data) {
   clearTimeout(retryTimer); retryTimer = null;
   if (!job || job.id !== data.id) { itemPage = 1; itemSequence++; itemBusy = false; $('#avdb-result-state').val(''); lastItemsCheck = 0; lastItemsSignature = null; pendingItemsSignature = null; }
   job = data; renderVersion++; $('#avdb-job').prop('hidden', false); $('#avdb-no-job').prop('hidden', true);
   if (global.AxImportScale) global.AxImportScale.jobRendered(data);
   $('#avdb-live-note').text(T('progress_saved', {time: new Date().toLocaleTimeString()}));
   renderJobState(data);
   var progress = jobProgress(data); if (progress === null) $('#avdb-progress').removeAttr('value'); else $('#avdb-progress').val(progress);
   $('#avdb-job-counts').text(T('job_counts', {p: data.processed, a: data.created, u: data.updated, s: data.skipped, f: data.failed})+' · '+(Number(data.max_items)===0?T('all_matching_short'):T('limit_n', {n: data.max_items})));
   var finished = ['complete','completed','rolled_back','rollback_conflict'].includes(data.state);
   if (lastItemsSignature !== itemSignature(data) && pendingItemsSignature !== itemSignature(data) && (finished || !automatic || Date.now() - lastItemsCheck > 3000)) loadItems(finished || !automatic);
   scheduleLive();
   if (Number(data.retry_after) > 0) {
    var deadline = Date.now() + Number(data.retry_after) * 1000, waitingJob = data.id;
    retryTimer = setTimeout(function updateWait() {
     if (!job || job.id !== waitingJob) return;
     job.retry_after = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
     renderJobState(job);
     if (job.retry_after) retryTimer = setTimeout(updateWait, 1000);
    }, 1000);
   }
  }
  function availableLanguages(item) {
   if (!Array.isArray(item.available_locales)) return label('languages_unknown','Not recorded');
   var codes = Array.from(new Set(item.available_locales.filter(function(code){return code !== 'en' && typeof code === 'string';})));
   return String(codes.length) + (codes.length ? ' · ' + codes.join(', ') : '');
  }
  function renderItems(items) {
   var rows = $('#avdb-job-results').empty();
   (items || []).forEach(function(item){var tr=$('<tr>');[item.remote_id,item.vod_id || '—',stateLabel(item.state),item.message,availableLanguages(item)].forEach(function(text){tr.append(node('td',text));});rows.append(tr);});
   if (!items.length) rows.append(node('tr').append(node('td',T('no_items'),{colspan:5})));
  }
  async function loadItems(force) {
   if (!job || !root.dataset.items || itemBusy && !force) return;
   var requestedJob = job.id, signature = itemSignature(job), sequence = ++itemSequence;
   itemBusy = true; pendingItemsSignature = signature; lastItemsCheck = Date.now();
   try {
    var data = await request('items',{job_id:requestedJob,pg:itemPage,pagesize:50,state:$('#avdb-result-state').val()});
    if (!job || job.id !== requestedJob || sequence !== itemSequence) return;
    lastItemsSignature = signature; renderItems(data.items || []); itemPage = Number(data.page || itemPage);
    $('#avdb-items-total').text(T('n_items', {n: Number(data.total || 0).toLocaleString()}));
    $('#avdb-items-page').text(T('page_n', {n: itemPage})); $('#avdb-items-prev').prop('disabled',itemPage<=1); $('#avdb-items-next').prop('disabled',!data.has_more);
   } catch(error) { if(sequence===itemSequence) message(error.message,true); }
   finally { if(sequence===itemSequence) { itemBusy=false; pendingItemsSignature=null; } }
  }
  function scheduleLive() {
   clearTimeout(liveTimer);
   if (!job || job.state !== 'running') return;
   liveTimer = setTimeout(async function(){
    if (!job || liveBusy) return;
    var currentJob=job.id, version=renderVersion, selection=jobSelection; liveBusy=true;
    try {
     var data=await request('status',{job_id:currentJob});
     if(job && job.id===currentJob && version===renderVersion && selection===jobSelection) renderJob(data);
    }catch(error){$('#avdb-live-note').text(T('live_unavailable'));}
    finally {liveBusy=false;scheduleLive();}
   },job.runner==='cli'?5000:1000);
  }
  function stopLoop() { automatic=false;clearTimeout(timer);timer=null; }

  async function advance(action) {
   if (!job || !automatic) return;
   var advancingJob = job.id, selection = jobSelection;
   try {
    var response = await request(action || 'step',{job_id:advancingJob},'POST');
    if (!job || job.id !== advancingJob || selection !== jobSelection) return;
    var continuing = action === 'rollback' ? response.phase === 'rollback' && !['rolled_back','rollback_conflict'].includes(response.state) : response.state === 'running';
    if (!continuing) stopLoop();
    renderJob(response);
    if (continuing && automatic) timer=setTimeout(function(){advance(action);},0);
    else loadJobs();
   } catch(e) {if(selection!==jobSelection)return;stopLoop();message(e.message,true);if(job)renderJob(job);}
  }
  async function loadJobs() {
   try {var jobs=await request('jobs'),select=$('#avdb-job-history').empty().append(node('option',T('choose_import'),{value:''}));(Array.isArray(jobs)?jobs:jobs.jobs || []).forEach(function(item){select.append(node('option',new Date(Number(item.created_at)*1000).toLocaleString()+' · '+stateLabel(item.state)+' · '+String(item.job_id).slice(0,8)+(item.provider?' · '+String(item.provider):'')+(item.type==='undo'?' · '+S('job_undo'):'')+(item.runner==='cli'?' · '+S('job_cli'):''),{value:item.job_id}));});if(job)select.val(job.id);}
   catch(e){message(e.message,true);}
  }
  var suggestionControls = [], activeSuggestion = null;
  function selectableSuggestion(field, value) {
   if(field!=='year')return true;
   var parts=/^(\d{4})(?:-(\d{2})-(\d{2}))?$/.exec(value);
   if(!parts)return false;
   var year=Number(parts[1]);if(year<1900 || year>new Date().getFullYear()+3)return false;
   if(!parts[2])return true;
   var date=new Date(Date.UTC(year,Number(parts[2])-1,Number(parts[3])));
   return date.getUTCFullYear()===year && date.getUTCMonth()===Number(parts[2])-1 && date.getUTCDate()===Number(parts[3]);
  }
  function closeSuggestions() {
   suggestionControls.forEach(function (control) { control.sequence++; clearTimeout(control.timer); control.box.prop('hidden', true); control.input.attr('aria-expanded', 'false').removeAttr('aria-activedescendant'); });
   activeSuggestion = null;
  }
  function initializeSuggestions() {
   $root.find('[data-suggest]').each(function (index) {
    var input = $(this), field = this.dataset.suggest, label = this.dataset.suggestLabel, id = 'avdb-suggestions-' + field;
    input.attr({id:'avdb-filter-' + field, autocomplete:'off', role:'combobox', 'aria-autocomplete':'list', 'aria-expanded':'false', 'aria-controls':id});
    var owner = input.closest('label'), wrapper = $('<div>', {class:'avdb-suggest-field'});
    owner.before(wrapper); wrapper.append(owner);
    var browse = node('button', T('browse', {what: label}), {type:'button', class:'avdb-browse', 'aria-label':T('browse_aria', {what: label})});
    var box = $('<div>', {class:'avdb-suggestions', hidden:true}), list = $('<ul>', {id:id, role:'listbox', 'aria-label':T('suggestions_of', {what: label})});
    var status = node('p', '', {class:'avdb-suggestion-status', role:'status'}), previous = node('button', T('previous'), {type:'button', class:'ax-btn ax-btn--sm'}), next = node('button', T('next'), {type:'button', class:'ax-btn ax-btn--sm'});
    var close = node('button', T('close'), {type:'button', class:'avdb-browse', 'aria-label':T('close_suggestions', {what: label})});
    box.append(status, list, $('<div>', {class:'avdb-suggestion-pages'}).append(previous, next, close)); wrapper.append(browse, box);
    var control = {input:input, box:box, list:list, status:status, previous:previous, next:next, timer:null, sequence:0, page:1, query:'', active:-1, values:[], field:field};
    suggestionControls.push(control);
    function choose(value) { if(!selectableSuggestion(field,value))return; input.val(value).trigger('change'); closeSuggestions(); input.trigger('focus'); }
    function highlight(number, direction) {
     if (!control.values.length) return;
     var attempts=0;number=(number+control.values.length)%control.values.length;
     while(!selectableSuggestion(field,control.values[number]) && attempts++<control.values.length)number=(number+(direction || 1)+control.values.length)%control.values.length;
     if(attempts>=control.values.length)return;
     control.active = number;
     list.children().attr('aria-selected','false'); var selectedOption=list.children().eq(control.active).attr('aria-selected','true');
     input.attr('aria-activedescendant',selectedOption.attr('id')); selectedOption[0].scrollIntoView({block:'nearest'});
    }
    async function load(nextPage, browseAll) {
     var query=browseAll?'':String(input.val() || '').trim();
     if (activeSuggestion !== control) closeSuggestions();
     activeSuggestion=control; control.query=query; control.page=nextPage; control.active=-1; control.values=[];
     var sequence=++control.sequence;
     box.prop('hidden',false); input.attr('aria-expanded','true').removeAttr('aria-activedescendant'); list.empty(); status.text(T('loading_what', {what: label})); previous.prop('disabled',true);next.prop('disabled',true);
     try {
      var response=await request('suggestions',{field:field,q:query,pg:nextPage,pagesize:20});
      if (sequence !== control.sequence || activeSuggestion !== control) return;
      control.values=(response.items || []).filter(function(value){return typeof value==='string' && value.length>0;});
      control.page=Number(response.page || nextPage);
      control.values.forEach(function(value,number){
       var available=selectableSuggestion(field,value);
       var option=node('li',value+(available?'':' · '+T('invalid_year')),{id:id+'-'+number,role:'option','aria-selected':'false','aria-disabled':available?'false':'true'});
       option.on('mousedown',function(event){event.preventDefault();}).on('click',function(){choose(value);}); list.append(option);
      });
      var source=response.source==='provider'?T('catalog'):(response.source || T('catalog'));
      var notice=response.warning || (response.complete===false?T('choices_preparing'):'');
      status.text(source + ' · ' + T('page_n', {n: control.page}) + ' · ' + (control.values.length ? T('n_choices', {n: control.values.length}) : T('no_values')) + (Number(response.updated_at)>0 ? ' · ' + T('updated_on', {date: new Date(Number(response.updated_at)*1000).toLocaleDateString()}) : '') + (notice ? ' · ' + notice : ''));
      previous.prop('disabled',control.page<=1); next.prop('disabled',!response.has_more);
     } catch(error) {
      if (sequence !== control.sequence || activeSuggestion !== control) return;
      status.text(error.message + ' ' + T('type_own'));
     }
    }
    browse.on('click',function(){load(1,true);});
    input.on('input',function(){clearTimeout(control.timer);control.sequence++;control.active=-1;control.values=[];list.empty();input.removeAttr('aria-activedescendant');previous.prop('disabled',true);next.prop('disabled',true);control.timer=setTimeout(function(){if(document.activeElement===input[0])load(1,false);},300);});
    input.on('keydown',function(event){
     if(event.key==='Escape'){closeSuggestions();return;}
     if(event.key==='ArrowDown' || event.key==='ArrowUp'){
      event.preventDefault(); if(box.prop('hidden'))load(1,false);else highlight(control.active<0?(event.key==='ArrowDown'?0:-1):control.active+(event.key==='ArrowDown'?1:-1),event.key==='ArrowDown'?1:-1);
     } else if(event.key==='Enter' && !box.prop('hidden') && control.active>=0){event.preventDefault();choose(control.values[control.active]);}
    });
    previous.on('click',function(){load(Math.max(1,control.page-1),control.query==='');});
    next.on('click',function(){load(control.page+1,control.query==='');});
    close.on('click',function(){closeSuggestions();input.trigger('focus');});
   });
   $(document).on('mousedown.avdbSuggestions',function(event){if(activeSuggestion && !activeSuggestion.box.closest('.avdb-suggest-field')[0].contains(event.target))closeSuggestions();});
   $(document).on('focusin.avdbSuggestions',function(event){if(activeSuggestion && !activeSuggestion.box.closest('.avdb-suggest-field')[0].contains(event.target))closeSuggestions();});
  }

  // ---- xvid-scale lane E (brief 3.8 step 1): provider tabs, database limit, scopes with the C7 estimate, provider status,
  // takedown review, undo and "remove all". Every branch reads provider capabilities (C7 providers), never the provider name.
  var xv = {providers: [], active: '', row: null, caps: {}, scopeCaps: Object.create(null), estimate: null, estimateSeq: 0, estimateTimer: null, scope: '', selections: Object.create(null), lastWebMinutes: 0, lastClosedMinutes: 0, lastBackground: true, legacy: false, dbAsked: null, userScope: false};
  var sourceKey = 'javcms-import-source-v1', dbAskKey = 'javcms-import-dblimit-answered-v1';
  function xvStore(key, value) { try { if (value === undefined) return localStorage.getItem(key); localStorage.setItem(key, value); } catch (e) { return null; } return null; }
  function xvNow() { return Math.floor(Date.now() / 1000); }
  function xvDate(ts) { var v = Number(ts || 0); return v > 0 ? new Date(v * 1000).toLocaleString() : S('st_unknown'); }
  function xvFilterNow() { return !xv.active || xvFilterCapable(xv.caps); }
  function xvFilterCapable(caps) { return !!(caps && (caps.suggestions || caps.tag_filter)); }
  function xvMode(mode) {
   $root.find('[data-mode-only]').each(function () { var only = this.getAttribute('data-mode-only'); $(this).prop('hidden', only !== mode); });
   $('#xv-large-link').prop('hidden', mode !== 'filter');
  }
  function xvRow(key) { for (var i = 0; i < xv.providers.length; i++) { if (xv.providers[i].key === key) return xv.providers[i]; } return null; }
  function xvTabs() {
   var box = $('#xv-sources').empty();
   xv.providers.forEach(function (p) {
    var tab = $('<button>', {type: 'button', role: 'tab', id: 'xv-tab-' + p.key, class: 'xv-source', 'aria-selected': p.key === xv.active ? 'true' : 'false', tabindex: p.key === xv.active ? '0' : '-1'});
    tab.append(node('span', p.label || p.key, {class: 'xv-source__name'}));
    var caps = p.capabilities || {}, sub = Array.isArray(caps.translations) && caps.translations.length ? S('tab_langs', {n: caps.translations.length}) : S('tab_original');
    if (p.videos !== undefined && p.videos !== null) sub += ' · ' + S('tab_videos', {n: num(p.videos)});
    tab.append(node('span', sub, {class: 'xv-source__sub'}));
    tab.on('click', function () { xvSelect(p.key, true); });
    box.append(tab);
   });
   box.off('keydown.xv').on('keydown.xv', function (event) {
    if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
    var tabs = box.find('[role=tab]'), at = tabs.index(document.activeElement); if (at < 0) return;
    event.preventDefault(); var next = tabs.eq((at + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length); next.trigger('focus').trigger('click');
   });
   box.prop('hidden', xv.providers.length < 2);
  }
  function xvStatus() {
   var p = xv.row; if (!p) { $('#xv-status').prop('hidden', true); return; }
   var lines = $('#xv-status-lines').empty(), now = xvNow(), st = p.state || null;
   function line(text, tone) { lines.append(node('li', text, {class: 'xv-status__line' + (tone ? ' is-' + tone : '')})); }
   if (p.videos !== undefined && p.videos !== null) line(S('st_videos', {n: num(p.videos)}));
   if (!st) line(S('st_pacer_unknown'));
   else if (Number(st.paused_until) > now) line(S('st_paused', {time: xvDate(st.paused_until), msg: st.message || S('st_unknown')}), 'bad');
   else if (Number(st.failures) > 0 && Number(st.retry_at) > now) line(S('st_backoff', {n: num(st.failures), time: xvDate(st.retry_at)}), 'warn');
   else if (Number(st.next_request_at) > now) line(S('st_waiting', {s: num(Number(st.next_request_at) - now), i: num(Math.round(Number(st.interval_ms || 0) / 1000))}));
   else line(S('st_ready', {i: num(Math.round(Number(st.interval_ms || 0) / 1000))}), 'ok');
   if (st && st.message && !(Number(st.paused_until) > now)) line(st.message, 'warn');
   line(Number(p.newest_remote_time) > 0 ? S('st_newest', {date: new Date(Number(p.newest_remote_time) * 1000).toLocaleDateString()}) : S('st_newest_unknown'));
   var seen = Number(p.cli_seen_at !== undefined ? p.cli_seen_at : (xv.estimate ? xv.estimate.cli_seen_at : 0));
   line(seen <= 0 ? S('st_cron_never') : (cliFresh(seen) ? S('st_cron_ok', {time: xvDate(seen)}) : S('st_cron_old', {time: xvDate(seen)})), seen > 0 && cliFresh(seen) ? 'ok' : 'warn');
   if (xv.estimate && 'db_limit_mb' in xv.estimate) line(S('dbl_current', {v: xv.estimate.db_limit_mb === null ? S('dbl_value_none') : fmtMB(xv.estimate.db_limit_mb)}));
   $('#xv-status').prop('hidden', false);
  }
  async function xvLoadProviders() {
   var data = await request('providers');
   xv.providers = (data && Array.isArray(data.providers) ? data.providers : []).filter(function (p) { return p && typeof p.key === 'string' && /^[a-z0-9_]{1,20}$/.test(p.key); });
   if (!xv.providers.length) throw new Error(S('st_unknown'));
   return xv.providers;
  }
  async function xvRefreshProviders() { try { await xvLoadProviders(); xv.row = xvRow(xv.active) || xv.row; xvTabs(); xvStatus(); xvManage(); } catch (e) { /* the page keeps the last status */ } }
  function xvSelect(key, user) {
   var row = xvRow(key) || xv.providers[0]; if (!row) return;
   xv.active = row.key; xv.row = row; xv.caps = row.capabilities || {}; xv.estimate = null; xv.scope = xvScopeAllowed('full', xv.caps, null) ? 'full' : ''; xv.userScope = false;
   if (user) xvStore(sourceKey, row.key);
   message('');
   xvTabs(); xvStatus(); xvManage();
   var filterMode = xvFilterCapable(xv.caps);
   xvMode(filterMode ? 'filter' : 'scope');
   // Capability-driven panels: the language panel only for providers with translations.
   $('#avdb-languages-set').prop('hidden', !(Array.isArray(xv.caps.translations) && xv.caps.translations.length));
   if (user) { if (filterMode) { if (!capabilities) connect(); panel('find'); } else panel('scope'); }
   else { xvPanelQuiet(filterMode ? 'find' : 'scope'); if (filterMode && !capabilities) connect(); }
   xvEstimateSoon(0);
  }
  async function xvInit() {
   $('#xv-scope-form').on('change input', function (event) { if (event.target.id === 'xv-confirm-input') { xvApply(); return; } if (event.target.name === 'preset') xvCats(); if (event.target.name === 'scope') { xv.userScope = true; xvScopeExtras(); } xvEstimateSoon(event.type === 'input' ? 500 : 0); });
   $('#xv-scope-form').on('submit', function (event) { event.preventDefault(); xvStart(); });
   $('#xv-cats-all').on('click', function () { $('#xv-cat-options input').prop('checked', true); xvCatCount(); xvEstimateSoon(0); });
   $('#xv-cats-none').on('click', function () { $('#xv-cat-options input').prop('checked', false); xvCatCount(); xvEstimateSoon(0); });
   $('#xv-dblimit-form').on('submit', function (event) { event.preventDefault(); xvSaveLimit(String($('#xv-dblimit-gb').val() || '').trim()); });
   $root.on('click', '[data-dblimit-choice]', function () { xvSaveLimit(this.getAttribute('data-dblimit-choice')); });
   $('#xv-dblimit-open').on('click', function () { $('#xv-dblimit').prop('hidden', false); $('#xv-dblimit-gb').trigger('focus'); });
   $root.on('click', '[data-copy]', function () { xvCopy($(this.getAttribute('data-copy')).text(), this); });
   $('#xv-takedown-delete').on('click', xvTakedownDelete);
   $('#xv-remove-btn').on('click', xvRemoveAll);
   try { await xvLoadProviders(); }
   catch (e) { xv.legacy = true; $('#xv-sources,#xv-status,#xv-manage').prop('hidden', true); xvMode('filter'); connect(); return; }
   var stored = xvStore(sourceKey);
   xvSelect(xvRow(stored) ? stored : xv.providers[0].key, false);
  }
  function xvScopeAllowed(scope, caps, scopeCaps) {
   if (scope === 'full') return !!caps.stable_cursor;
   if (scope === 'category') return !!(scopeCaps && Array.isArray(scopeCaps.provider_categories) && scopeCaps.provider_categories.length);
   return true;
  }
  function xvScopeOptions() {
   var box = $('#xv-scope-options'), sc = xv.scopeCaps[xv.active] || null, e = xv.estimate, fullOk = xvScopeAllowed('full', xv.caps, sc);
   // A1: "Whole catalog (recommended)" is first and preselected whenever the source has a full mode.
   var def = fullOk ? 'full' : (e && e.default_scope ? e.default_scope : '');
   var checked = $('#xv-scope-form [name=scope]:checked').val() || '', current = xv.userScope && checked ? checked : (xv.scope || def || checked || 'recent_10000');
   box.empty();
   SCOPES.forEach(function (scope) {
    if (!xvScopeAllowed(scope, xv.caps, sc)) return;
    var id = 'xv-scope-' + scope, card = $('<label>', {class: 'xv-scope' + (scope === 'full' ? ' xv-scope--full' : ''), for: id});
    var radio = $('<input>', {type: 'radio', name: 'scope', value: scope, id: id}).prop('checked', scope === current);
    var text = $('<span>', {class: 'xv-scope__text'});
    if (scope === 'full') text.append(node('span', S('sc_full_recommended'), {class: 'xv-scope__name'}), node('span', S('sc_full_hint'), {class: 'xv-scope__hint'}));
    else {
     text.append(node('span', S('sc_' + scope), {class: 'xv-scope__name'}));
     if (scope === def) text.append($('<span>', {class: 'xv-scope__tags'}).append(node('span', S('sc_default'), {class: 'ax-chip ax-chip--ok'})));
    }
    card.append(radio, text);
    box.append(card);
   });
   if (!box.find('[name=scope]:checked').length) box.find('[name=scope]').first().prop('checked', true);
   xvScopeExtras();
  }
  function xvScopeExtras() {
   var scope = $('#xv-scope-form [name=scope]:checked').val() || '';
   xv.scope = scope;
   $('#xv-recent-n').prop('hidden', scope !== 'recent');
   $('#xv-category-pick').prop('hidden', scope !== 'category');
  }
  function xvCats() {
   var sc = xv.scopeCaps[xv.active] || {}, presets = sc.presets || null, preset = $('#xv-scope-form [name=preset]:checked').val() || 'grouped', box = $('#xv-cat-options').empty();
   var list = presets && Array.isArray(presets[preset]) ? presets[preset] : [];
   var remembered = xv.selections[xv.active + ':' + preset] || null;
   list.forEach(function (item) {
    var ids = (item.remote_ids || []).map(Number).filter(function (n) { return Number.isSafeInteger(n) && n > 0; }); if (!ids.length) return;
    var on = remembered ? remembered.indexOf(String(item.key)) >= 0 : item.default_on !== false;
    var cb = $('<input>', {type: 'checkbox', value: String(item.key)}).attr('data-ids', ids.join(',')).prop('checked', on);
    cb.on('change', function () { xvCatCount(); });
    box.append($('<label>', {class: 'xv-cat'}).append(cb, node('span', item.label || item.key)));
   });
   if (!box.children().length) box.append(node('p', S('pr_unavailable'), {class: 'avdb-hint'}));
   xvCatCount();
  }
  function xvCatCount() {
   var all = $('#xv-cat-options input'), on = all.filter(':checked'), preset = $('#xv-scope-form [name=preset]:checked').val() || 'grouped';
   xv.selections[xv.active + ':' + preset] = on.map(function () { return this.value; }).get();
   $('#xv-cats-count').text(all.length ? S('pr_count', {n: num(on.length), total: num(all.length)}) : '');
  }
  async function xvLoadScopeCaps(key) {
   if (xv.scopeCaps[key]) return xv.scopeCaps[key];
   try { xv.scopeCaps[key] = await request('capabilities', {provider: key}) || {}; } catch (e) { xv.scopeCaps[key] = {}; }
   return xv.scopeCaps[key];
  }
  async function xvRenderScope() {
   var key = xv.active, sc = await xvLoadScopeCaps(key); if (key !== xv.active) return;
   var cats = Array.isArray(sc.provider_categories) ? sc.provider_categories : [], select = $('#xv-category-pick select'), keep = select.val();
   select.empty(); cats.forEach(function (c) { var label = c.remote_total ? S('sc_category_n', {name: c.type_name, n: num(c.remote_total)}) : String(c.type_name || c.type_id); select.append(node('option', label, {value: c.type_id})); });
   if (keep && select.find('option[value="' + keep + '"]').length) select.val(keep);
   var langs = Array.isArray(sc.source_languages) ? sc.source_languages : [], lbox = $('#xv-lang-options').empty();
   langs.forEach(function (l) { if (!l || typeof l.code !== 'string') return; lbox.append($('<label>').append($('<input>', {type: 'checkbox', name: 'langs', value: l.code}).prop('checked', true), node('span', S('fl_lang_share', {name: l.label || l.code, p: num(Math.round(Number(l.share || 0) * 100))})))); });
   $('#xv-langs').prop('hidden', !langs.length);
   var parent = xv.caps.category_parent;
   $('#xv-presets').prop('hidden', !parent || !sc.presets);
   $('#xv-filters').prop('hidden', !parent);
   $('#xv-parent-note').text(parent ? S('pr_parent', {name: parent}) : '');
   xvCats(); xvScopeOptions();
  }
  function xvPanelQuiet(name) {
   ['find','review','scope','run'].forEach(function (key) { $(key === 'scope' ? '#xv-scope' : '#avdb-' + key).prop('hidden', key !== name); $root.find('.avdb-steps [data-panel="' + key + '"]').attr('aria-current', key === name ? 'step' : null); });
   if (name === 'scope') xvOpenScope();
  }
  function xvOpenScope() { xvRenderScope(); xvEstimateSoon(0); }
  function xvParams() {
   var f = {}; $('#xv-scope-form').serializeArray().forEach(function (item) { if (item.name === 'langs') return; f[item.name] = item.value; });
   var p = {provider: xv.active};
   if (xv.scope || f.scope) p.scope = f.scope || xv.scope;
   if (p.scope === 'recent') p.limit = Math.max(1, Math.floor(Number(f.limit || 0)) || 1);
   if (p.scope === 'category' && f.type) p.type = f.type;
   p.images = $('#xv-images').prop('checked') ? 1 : 0;
   if (!$('#xv-filters').prop('hidden')) { p.skip_short = f.skip_short ? 1 : 0; p.skip_unknown = f.skip_unknown ? 1 : 0; p.skip_sd = f.skip_sd ? 1 : 0; }
   if (!$('#xv-langs').prop('hidden')) p.langs = $('#xv-lang-options input:checked').map(function () { return this.value; }).get().join(',');
   if (!$('#xv-presets').prop('hidden')) {
    p.preset = $('#xv-scope-form [name=preset]:checked').val() || 'grouped';
    var off = []; $('#xv-cat-options input').each(function () { if (!this.checked) off = off.concat(String(this.getAttribute('data-ids') || '').split(',').filter(Boolean)); });
    p.categories_off = off.join(',');
   }
   return p;
  }
  function xvEstimateSoon(delay) { clearTimeout(xv.estimateTimer); xv.estimateTimer = setTimeout(xvEstimate, delay || 0); }
  async function xvEstimate() {
   if (!xv.active || xv.legacy) return;
   var seq = ++xv.estimateSeq, params = xvParams();
   $('#xv-estimate-state').text(S('es_loading')); $('#xv-start').prop('disabled', true);
   try {
    var data = await request('estimate', params); if (seq !== xv.estimateSeq) return;
    var e = data && data.estimate ? data.estimate : data;
    if (!e || typeof e !== 'object') throw new Error(S('st_unknown'));
    var first = !xv.estimate; xv.estimate = e;
    if (first && !params.scope) { xv.scope = e.default_scope || ''; }
    xvLimitQuestion(e); xvStatus();
    if (!$('#xv-scope').prop('hidden')) {
     if (first) xvScopeOptions();
     // The first answer estimated the server default; when the echo says otherwise, ask again for the chosen scope.
     if (first && !params.scope && e.scope !== undefined && e.scope !== e.default_scope) { xvEstimateSoon(0); return; }
     if (params.scope && $('#xv-scope-form [name=scope]:checked').val() && $('#xv-scope-form [name=scope]:checked').val() !== params.scope) { xvEstimateSoon(0); return; }
    }
    xvRenderEstimate(e);
   } catch (error) { if (seq !== xv.estimateSeq) return; xv.estimate = null; $('#xv-estimate-list').empty(); $('#xv-verdict,#xv-cli,#xv-web-note,#xv-confirm,#xv-background').prop('hidden', true); $('#xv-warnings').empty(); $('#xv-estimate-state').text(S('es_failed', {msg: error.message})); xvApply(); }
  }
  function xvLimitQuestion(e) {
   var answered = e.db_limit_asked !== undefined ? !!e.db_limit_asked : (e.db_limit_mb !== null || xvStore(dbAskKey) === '1');
   if (xv.dbAsked === null) xv.dbAsked = answered;
   if (!answered) { $('#xv-dblimit').prop('hidden', false); }
  }
  async function xvSaveLimit(value) {
   if (value !== 'auto' && value !== 'none' && !/^\d{1,4}(?:\.\d{1,2})?$/.test(value)) { $('#xv-dblimit-msg').text(S('dbl_invalid')); return; }
   if (value !== 'auto' && value !== 'none' && Number(value) <= 0) { $('#xv-dblimit-msg').text(S('dbl_invalid')); return; }
   try {
    var data = await request('dblimit', {gb: value}, 'POST'), mb = data && 'db_limit_mb' in data ? data.db_limit_mb : null;
    xvStore(dbAskKey, '1'); xv.dbAsked = true;
    $('#xv-dblimit').prop('hidden', true); $('#xv-dblimit-msg').text('');
    message(S('dbl_saved', {v: mb === null ? S('dbl_value_none') : fmtMB(mb)}));
    xv.estimate = null; xvEstimateSoon(0);
   } catch (error) { $('#xv-dblimit-msg').text(error.message); }
  }
  function xvRow2(dl, label, value) { dl.append(node('dt', label), node('dd', value)); }
  // Source of the rows (C19 `source`): xvidapi snapshot files, export pages (keyset), id windows, or list pages.
  function xvSourceText(e, scope) {
   var src = e.source || (scope === 'full' && xv.caps.stable_cursor === 'id_windows' ? 'windows' : 'pages');
   // fix-P estimate: requests = 1 manifest + shards + feed pages (>= 1), so shards = requests - 2 when not sent.
   if (src === 'snapshot') return S('es_source_snapshot', {n: num(e.shards !== undefined ? e.shards : (e.files !== undefined ? e.files : Math.max(1, Number(e.requests || 0) - 2))), size: fmtMB(e.download_mb)});
   if (src === 'keyset' || src === 'export') return S('es_source_keyset', {n: num(e.requests), t: fmtMin(e.provider_minutes)});
   if (src === 'windows') return S('es_source_windows', {n: num(e.requests), t: fmtMin(e.provider_minutes)});
   return S('es_source_pages', {n: num(e.requests), t: fmtMin(e.provider_minutes)});
  }
  // A3: false when PHP cannot answer first and keep working (no fastcgi_finish_request / litespeed_finish_request).
  function xvBackground(e) { return e && e.background !== undefined ? !!e.background : root.dataset.background !== '0'; }
  function xvRenderEstimate(e) {
   var dl = $('#xv-estimate-list').empty(), scope = xv.scope || e.scope || '', cli = cliFresh(e.cli_seen_at), times = importTimes(e), stop = stopAt(e), runner = runnerFor(e);
   // A2: a job that stops at the storage target imports only the newest part: sizes and times shrink with it (unless the
   // server already computed them for the stop: scaled_to_stop).
   var ratio = stop && !e.scaled_to_stop && Number(e.videos) > 0 ? stop.videos / Number(e.videos) : 1, now0 = Number(e.db_mb_now || 0);
   function part(v) { var n = Number(v); return Number.isFinite(n) ? n * ratio : v; }
   times = {open: part(times.open), closed: part(times.closed), cron: part(times.cron)};
   $('#xv-estimate-state').text('');
   xvRow2(dl, S('es_videos'), stop ? S('es_videos_stop', {n: num(stop.videos), total: num(e.videos)}) : num(e.videos));
   xvRow2(dl, S('es_source'), xvSourceText(ratio < 1 ? Object.assign({}, e, {requests: Math.ceil(part(e.requests)), provider_minutes: part(e.provider_minutes), download_mb: part(e.download_mb), shards: e.shards !== undefined ? Math.ceil(part(e.shards)) : undefined}) : e, scope));
   // Time: tab open, tab closed (visitors or cron), cron. Measured rate of this site when it has one (C7 rate_items_s).
   dl.append(node('dt', S('es_time')));
   var tdd = $('<dd>', {class: 'xv-times'}), rate = Number(e.rate_items_s), measured = Number.isFinite(rate) && rate > 0 && Math.abs(rate - 125) > 0.05;
   tdd.append(node('span', measured ? S('es_time_open_rate', {t: fmtMin(times.open), rate: num(Math.round(rate))}) : S('es_time_open', {t: fmtMin(times.open)})));
   tdd.append(node('span', xvBackground(e) ? S('es_tab_closed', {t: fmtMin(times.closed)}) : S('es_tab_closed_none')));
   tdd.append(node('span', S('es_time_cron', {t: fmtMin(times.cron)})));
   dl.append(tdd);
   xvRow2(dl, S('es_db'), S('es_db_v', {lean: fmtMB(part(e.db_mb_lean)), full: fmtMB(part(e.db_mb_full))}));
   var after = e.db_limit_mb !== null && e.db_limit_mb !== undefined ? S('es_after_limit', {now: fmtMB(e.db_mb_now), after: fmtMB(stop && stop.db_mb !== null ? Math.min(Number(e.db_mb_after), stop.db_mb) : e.db_mb_after), limit: fmtMB(e.db_limit_mb)}) : S('es_after_v', {now: fmtMB(e.db_mb_now), after: fmtMB(e.db_mb_after)});
   dl.append(node('dt', S('es_after')));
   var dd = node('dd', after);
   if (e.db_limit_mb) { var share = Math.min(1, (stop && stop.db_mb !== null ? Math.min(Number(e.db_mb_after), stop.db_mb) : Number(e.db_mb_after)) / Number(e.db_limit_mb)); dd.append($('<span>', {class: 'xv-meter' + (share > 0.8 ? ' is-over' : ''), role: 'img', 'aria-label': S('es_meter', {p: num(Math.round(share * 100))})}).append($('<span>').css('width', Math.round(share * 100) + '%'))); }
   dl.append(dd);
   var peak = ratio < 1 ? now0 + Math.max(0, Number(e.peak_mb || 0) - now0) * ratio : e.peak_mb;
   xvRow2(dl, S('es_peak'), e.tmp_free_mb !== null && e.tmp_free_mb !== undefined ? S('es_peak_free', {v: fmtMB(peak), free: fmtMB(e.tmp_free_mb)}) : S('es_peak_v', {v: fmtMB(peak)}));
   if (Number(e.images_mb) > 0) xvRow2(dl, S('es_images'), S('es_images_v', {v: fmtMB(part(e.images_mb))}));
   $('#xv-images-label').text(S('im_label', {mb: fmtMB(part(Number(e.videos)) * 12.7 / 1024)}));
   $('#xv-lang-note').text(e.lang_note || '').prop('hidden', !e.lang_note);
   // Verdict: fits, or "stops at about N videos when the database reaches X" (A2; the start button stays).
   var verdict = $('#xv-verdict').removeClass('ax-notice--warn ax-notice--danger ax-notice--ok').empty();
   if (!stop) verdict.addClass('ax-notice--ok').append(node('p', S('es_fits')));
   else verdict.addClass('ax-notice--warn').append(node('p', stop.db_mb !== null ? S('es_stop_at', {n: num(stop.videos), gb: fmtMB(stop.db_mb)}) : S('es_stop_at_disk', {n: num(stop.videos)})));
   verdict.prop('hidden', false);
   var warn = $('#xv-warnings').empty(), bin = e.binlog || {};
   if (Array.isArray(e.warnings)) {
    // Lane P sends its warnings already translated (C7 note / HO-P-3); the cron box below covers the command-line hints.
    var hasBin = false;
    e.warnings.forEach(function (w) { if (!w || typeof w.text !== 'string' || w.code === 'needs_cli' || w.code === 'cli_recommended') return; if (w.code === 'binlog') hasBin = true; warn.append($('<div>', {class: 'ax-notice ax-notice--warn', role: 'note'}).append(node('p', w.text))); });
    if (bin.on && !hasBin) warn.append(node('p', S('es_binlog_info', {mb: fmtMB(bin.est_mb || 0), days: num(bin.expire_days || 0)}), {class: 'avdb-hint'}));
   }
   else if (bin.on && Number(e.videos) > 500000) warn.append($('<div>', {class: 'ax-notice ax-notice--warn', role: 'note'}).append(node('p', S('es_binlog_warn', {gb: (Number(bin.est_mb || 0) / 1024).toLocaleString(undefined, {maximumFractionDigits: 1}), days: num(bin.expire_days || 0)}))));
   else if (bin.on) warn.append(node('p', S('es_binlog_info', {mb: fmtMB(bin.est_mb || 0), days: num(bin.expire_days || 0)}), {class: 'avdb-hint'}));
   if (e.slim_offer) warn.append($('<div>', {class: 'ax-notice', role: 'note'}).append(node('p', S('es_slim')), node('pre', 'php tools/db-tune.php --slim-indexes --dry-run', {class: 'ax-code xv-code'})));
   // A3: hosts that cannot finish a request early do not continue the import from page views.
   $('#xv-background').text(S('es_no_background')).prop('hidden', runner !== 'web' || xvBackground(e));
   // Cron: optional and faster. Shown for big jobs while cron is not seen, and as "ready" when the job goes to cron.
   var big = Number(e.videos) > 100000 || !!e.cli_recommended || !!e.needs_cli;
   if (runner === 'cli' || (big && !cli) || !xvBackground(e)) {
    $('#xv-cli').prop('hidden', false); $('#xv-cli-title').text(runner === 'cli' ? S('es_cli_title_ready') : S('es_cli_title'));
    $('#xv-cli-text').text(runner === 'cli' ? S('es_cli_ready') : S('es_cli_need'));
    $('#xv-cron-line').text(String(e.cron_line || ''));
    $('#xv-cli-bins').text(Array.isArray(e.php_binaries) && e.php_binaries.length ? S('es_cli_bins', {list: e.php_binaries.join(', ')}) : '');
    var seen = Number(e.cli_seen_at || 0);
    $('#xv-cli-seen').text(seen <= 0 ? S('st_cron_never') : (cli ? S('st_cron_ok', {time: xvDate(seen)}) : S('st_cron_old', {time: xvDate(seen)}))).attr('class', cli ? 'xv-ok' : 'xv-bad');
   } else $('#xv-cli').prop('hidden', true);
   xv.lastWebMinutes = runner === 'web' ? Number(times.open || 0) : 0; xv.lastClosedMinutes = Number(times.closed || 0); xv.lastBackground = xvBackground(e);
   $('#xv-web-note').text(runner === 'web' ? xvKeepOpenText() : '').prop('hidden', runner !== 'web');
   // A1: one checkbox with the numbers, only for jobs above 1,000,000 videos or above half of the space left.
   if (confirmNeeded(e)) {
    var left = spaceLeft(e), grow = Math.max(0, Number(e.db_mb_after || 0) - Number(e.db_mb_now || 0));
    $('#xv-confirm').prop('hidden', false);
    $('#xv-confirm-text').text(S('es_confirm_label', {n: num(stop ? stop.videos : e.videos), gb: fmtMB(stop && stop.db_mb !== null ? Math.max(0, stop.db_mb - Number(e.db_mb_now || 0)) : grow), space: left === null ? S('st_unknown') : fmtMB(left), t: fmtMin(runner === 'cli' ? times.cron : times.open)}));
    $('#xv-confirm-help').text(S('es_confirm_help'));
   } else { $('#xv-confirm').prop('hidden', true); $('#xv-confirm-input').prop('checked', false); }
   xvApply();
  }
  function xvKeepOpenText() {
   return xv.lastBackground === false ? S('es_web_note_fg', {t: fmtMin(Math.max(1, xv.lastWebMinutes))}) : S('es_web_note', {t: fmtMin(Math.max(1, xv.lastWebMinutes)), closed: fmtMin(xv.lastClosedMinutes)});
  }
  function xvApply() {
   var e = xv.estimate, d = startDecision(e, {confirmed: $('#xv-confirm-input').prop('checked')});
   var start = $('#xv-start');
   start.text(d.runner === 'cli' ? S('es_queue') : S('es_start'));
   start.prop('disabled', mutationBusy || !(d.action === 'start'));
   start.attr('title', d.action === 'confirm' ? S('es_confirm_first') : null);
  }
  async function xvStart() {
   var e = xv.estimate, d = startDecision(e, {confirmed: $('#xv-confirm-input').prop('checked')});
   if (d.action !== 'start') { message(d.action === 'confirm' ? S('es_confirm_first') : S('es_refused'), true); return; }
   var p = xvParams(), options = {provider: p.provider, scope: p.scope || e.default_scope, runner: d.runner, publish: 1, images: p.images};
   ['limit', 'type', 'skip_short', 'skip_unknown', 'skip_sd', 'preset'].forEach(function (k) { if (p[k] !== undefined) options[k] = p[k]; });
   if (p.langs !== undefined) options.langs = p.langs ? p.langs.split(',') : [];
   if (p.categories_off !== undefined) options.categories_off = p.categories_off ? p.categories_off.split(',').map(Number) : [];
   // A1: confirm=1 (the checkbox); a server that still sends a confirm_phrase (before C19) gets its phrase back.
   if (options.scope === 'full' || confirmNeeded(e)) options.confirm = e.confirm_phrase ? String(e.confirm_phrase) : '1';
   stopLoop(); var selection = ++jobSelection; $('#xv-start').prop('disabled', true);
   try {
    var data = await request('start', {options: options}, 'POST'); if (selection !== jobSelection) return;
    renderJob(data); panel('run');
    if (data.runner === 'cli' || d.runner === 'cli') { message(S('es_queued')); scheduleLive(); }
    else { message(S('es_started')); automatic = true; advance(); }
    xvRefreshProviders();
   } catch (error) { if (selection === jobSelection) message(error.message, true); }
   finally { xvApply(); }
  }
  function xvJobRendered(data) {
   $('#xv-job-runner').text(data.runner === 'cli' ? S('job_cli') : (data.runner === 'web' ? S('job_web') : ''));
   var webRunning = data.runner !== 'cli' && data.state === 'running' && data.type !== 'undo' && xv.lastWebMinutes > 0;
   $('#xv-keep-open').text(webRunning ? xvKeepOpenText() : '').prop('hidden', !webRunning);
  }
  function xvUndoRate() { var r = xv.row && Number(xv.row.undo_rate_s) > 0 ? Number(xv.row.undo_rate_s) : 40; return r; }
  function xvUndoOpen() {
   var n = job ? Number(job.created || 0) : 0, t = fmtMin(n / xvUndoRate() / 60);
   $('#xv-undo-text').text(S('mg_undo_confirm', {n: num(n), t: t}) + ' ' + T('undo_text'));
  }
  async function xvUndoRun() {
   if (!job) return;
   var targetJob = job.id, selection = jobSelection;
   if (root.dataset.undo) {
    try { var data = await request('undo', {job_id: targetJob}, 'POST'); if (selection !== jobSelection) return; renderJob(data); automatic = true; advance(); return; }
    catch (error) { if (error.status !== 404) { message(error.message, true); return; } }
   }
   automatic = true; advance('rollback');
  }
  function xvManage() {
   var p = xv.row; if (!p || xv.legacy) { $('#xv-manage').prop('hidden', true); return; }
   var name = p.label || p.key, td = p.takedowns || null;
   $('#xv-manage-title').text(S('mg_title', {name: name}));
   $('#xv-remove-title').text(S('mg_remove_title', {name: name}));
   var videos = p.videos !== undefined && p.videos !== null ? Number(p.videos) : null;
   if (videos === null) { $('#xv-remove-text').text(S('mg_videos_unknown')); $('#xv-remove-btn').prop('hidden', true); }
   else if (videos <= 0) { $('#xv-remove-text').text(S('mg_none')); $('#xv-remove-btn').prop('hidden', true); }
   else { $('#xv-remove-text').text(S('mg_remove_text', {n: num(videos), name: name, t: fmtMin(videos / xvUndoRate() / 60)})); $('#xv-remove-btn').text(S('mg_remove_btn', {name: name})).prop('hidden', false); }
   $('#xv-sweep').removeClass('xv-bad').text('');
   if (!td) { $('#xv-takedown-summary').text(S('mg_takedown_unknown')); $('#xv-takedown-table,#xv-takedown-delete').prop('hidden', true); }
   else {
    var hidden = Number(td.hidden || 0), old = Number(td.older_30d || 0);
    $('#xv-takedown-summary').text(hidden > 0 ? S('mg_takedown_n', {n: num(hidden), old: num(old)}) : S('mg_takedown_none'));
    if (td.sweep_state === 'aborted') $('#xv-sweep').addClass('xv-bad').text(S('mg_sweep_aborted', {msg: td.sweep_msg || S('st_unknown')}));
    else if (Number(td.sweep_at) > 0) $('#xv-sweep').text(S('mg_sweep_ok', {time: xvDate(td.sweep_at)}));
    $('#xv-takedown-delete').text(S('mg_takedown_delete', {n: num(old)})).prop('hidden', !(old > 0)).attr('data-count', String(old));
    if (hidden > 0) xvTakedownList(p.key); else $('#xv-takedown-table').prop('hidden', true);
   }
   $('#xv-manage').prop('hidden', false);
  }
  async function xvTakedownList(key) {
   try {
    var data = await request('takedowns', {provider: key, pg: 1}); if (key !== xv.active) return;
    var rows = $('#xv-takedown-rows').empty(), items = data && Array.isArray(data.items) ? data.items : [];
    items.slice(0, 20).forEach(function (it) { rows.append($('<tr>').append(node('td', it.vod_name || ('#' + it.vod_id)), node('td', it.remote_id), node('td', xvDate(it.hidden_at)))); });
    if (Number(data.total || 0) > items.length) rows.append($('<tr>').append(node('td', S('mg_more', {n: num(Number(data.total) - items.length)}), {colspan: 3})));
    $('#xv-takedown-table').prop('hidden', !items.length);
   } catch (error) { $('#xv-takedown-table').prop('hidden', true); }
  }
  async function xvTakedownDelete() {
   var n = Number(this.getAttribute('data-count') || 0), key = xv.active; if (!n) return;
   var ok = global.AxUX && AxUX.confirm ? await AxUX.confirm({title: S('mg_takedown_title'), message: S('mg_takedown_confirm', {n: num(n)}), confirmLabel: S('mg_delete_ok'), danger: true, count: n}) : global.confirm(S('mg_takedown_confirm', {n: num(n)}));
   if (!ok) return;
   try { var data = await request('takedownDelete', {provider: key, scope: 'older_30d'}, 'POST'); message(S('mg_takedown_deleted', {n: num(data && data.deleted || 0)})); xvRefreshProviders(); }
   catch (error) { message(error.message, true); }
  }
  async function xvRemoveAll() {
   var p = xv.row; if (!p || !(Number(p.videos) > 0)) return;
   var n = Number(p.videos), phrase = 'REMOVE ' + n + ' VIDEOS', name = p.label || p.key;
   var ok = global.AxUX && AxUX.confirm ? await AxUX.confirm({title: S('mg_remove_title', {name: name}), message: S('mg_remove_confirm', {n: num(n), name: name}), confirmLabel: S('mg_remove_ok'), danger: true, typed: phrase, count: n}) : false;
   if (!ok) return;
   stopLoop(); var selection = ++jobSelection;
   try { var data = await request('removeAll', {provider: p.key, confirm: phrase}, 'POST'); if (selection !== jobSelection) return; renderJob(data); panel('run'); automatic = true; advance(); xvRefreshProviders(); }
   catch (error) { if (selection === jobSelection) message(error.message, true); }
  }
  function xvCopy(text, button) {
   var done = function () { var old = button.textContent; button.textContent = S('es_copied'); setTimeout(function () { button.textContent = old; }, 1500); };
   try { if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, function () {}); return; } } catch (e) { /* fall through */ }
   var area = $('<textarea>').val(text).css({position: 'fixed', left: '-9999px'}).appendTo(document.body); area[0].select(); try { document.execCommand('copy'); done(); } catch (e) { /* manual copy */ } area.remove();
  }
  global.AxImportScale = {openScope: xvOpenScope, jobRendered: xvJobRendered, state: xv};

  function populatePresets() {
   var select=$('#avdb-preset');select.find('[data-saved-preset]').remove();presets.forEach(function(p,index){select.append(node('option',p.name,{value:'saved-'+index,'data-saved-preset':'true'}));});
  }
  async function connect() {
   $('#avdb-connect').prop('disabled',true).text(T('reconnect'));$('#avdb-connection-text').text(T('connecting'));
   // C11: AVDB has not answered after 20 s: say so and offer Retry (the request itself keeps running).
   var connectSlow=setTimeout(function(){$('#avdb-connection-text').text(T('timeout'));$('#avdb-connect').prop('disabled',false).text(T('retry'));},20000);
   try {capabilities=await request('capabilities');var categories=$('#avdb-category').empty().append(node('option',T('all_categories'),{value:''}));(capabilities.provider_categories || []).forEach(function(c){categories.append(node('option',c.type_name,{value:c.type_id}));});
    var oldLanguages=$('#avdb-language-options input').length ? $('#avdb-language-options input:checked').map(function(){return this.value;}).get() : null;
    var previousPlayer=$('#avdb-default-player').val(), defaultPlayers=$('#avdb-default-player').empty().append(node('option',T('require_mappings'),{value:''}));
    (capabilities.players || []).forEach(function(player){var key=player.key || player.from || player.id; defaultPlayers.append(node('option',(player.name || player.show || key)+' ('+key+')',{value:key}));});
    defaultPlayers.val(defaultPlayerInitialized && defaultPlayers.find('option').filter(function(){return this.value===previousPlayer;}).length ? previousPlayer : defaultPlayers.find('option[value=ngm3u8]').length ? 'ngm3u8' : defaultPlayers.find('option').eq(1).val() || ''); defaultPlayerInitialized=true;
    var languages=$('#avdb-language-options').empty();Object.keys(capabilities.languages || {}).filter(function(code){return code!=='en';}).forEach(function(code){languages.append(node('label',capabilities.languages[code]).prepend($('<input>',{type:'checkbox',value:code}).prop('checked',oldLanguages===null || oldLanguages.includes(code))));});
    clearTimeout(connectSlow);$('#avdb-connect').text(T('reconnect'));$('#avdb-connection-text').text(capabilities.provider_error?T('provider_unavailable', {msg: capabilities.provider_error}):T('connected', {n: (capabilities.provider_categories || []).length}));
    summary();if(!capabilities.provider_error)await loadPreview(1);else{message('');loadFailed(connect);}loadJobs();
   }catch(e){clearTimeout(connectSlow);message(e.message,true);$('#avdb-connection-text').text(T('connect_failed'));$('#avdb-connect').text(T('retry'));loadFailed(connect);}
   finally{clearTimeout(connectSlow);$('#avdb-connect').prop('disabled',false);}
  }
  $root.on('click','[data-panel]',function(){panel(this.dataset.panel);});
  $('#avdb-filter-form').on('input change',function(event){previewReady=false; summary();}).on('submit',function(e){e.preventDefault();loadPreview(1);});$('#avdb-reset').on('click',function(){document.getElementById('avdb-filter-form').reset();closeSuggestions();loadPreview(1);});
  $('#avdb-connect').on('click',connect);$('#avdb-prev').on('click',function(){loadPreview(Math.max(1,page-1));});$('#avdb-next').on('click',function(){loadPreview(page+1);});$('#avdb-go-page').on('click',function(){var number=Number($('#avdb-page-input').val());if(Number.isSafeInteger(number)&&number>0)loadPreview(number);else message(T('page_positive'),true);});
  $('#avdb-select-page').on('change',function(){var checked=this.checked;try{previewRows.forEach(function(row){selected.toggle(row,checked);});}catch(e){message(e.message,true);}finally{renderRows();}});
  $('#avdb-clear-selection').on('click',function(){selected.clear();renderRows();});
  $('#avdb-options-form').on('change input',function(e){var name=e.target.name;if(name==='mode'){$('.avdb-range').prop('hidden',values(this).mode!=='pages');renderMappings();}if(name==='language_mode')$('#avdb-languages').prop('hidden',values(this).language_mode!=='multi');if(name==='existing'){$('#avdb-update-fields').prop('hidden',values(this).existing!=='update');$('#avdb-smart-help').prop('hidden',values(this).existing!=='smart');}summary();});
  $('#avdb-default-player').on('change',function(){var defaultPlayer=this.value;Object.keys(playerMap).forEach(function(source){if(playerAutoDefault[source] && !playerOverrides[source])playerMap[source]=defaultPlayer;});renderMappings();summary();});
  $('#avdb-unlimited').on('change',summary);
  $('#avdb-create-categories').on('change',function(){renderMappings();summary();});
  $('#avdb-limit-preset').on('change',function(){if(this.value){$('#avdb-options-form [name=max_items]').val(this.value);summary();}});
  $('#avdb-all-languages').on('click',function(){$('#avdb-language-options input').prop('checked',true);summary();});$('#avdb-no-languages').on('click',function(){$('#avdb-language-options input').prop('checked',false);summary();});
  $('#avdb-options-form').on('submit',async function(e){e.preventDefault();var options=importOptions();if(options.mode!=='selected'&&!currentPreviewMatches()){message(label('preview_required','Apply the current filters successfully before importing matching videos or a page range.'),true);return;}if(!options.ids.length&&options.mode==='selected'){message(T('select_first'),true);return;}stopLoop();var selection=++jobSelection;$('#avdb-start').prop('disabled',true);try{var data=await request('start',{options:options},'POST');if(selection!==jobSelection)return;renderJob(data);panel('run');automatic=true;advance();}catch(error){if(selection===jobSelection)message(error.message,true);}finally{summary();}});
  $root.on('click','[data-job-action]',async function(){if(!job)return;var action=this.dataset.jobAction, targetJob=job.id, selection=jobSelection;stopLoop();try{var data=await request(action,{job_id:targetJob},'POST');if(selection!==jobSelection||!job||job.id!==targetJob)return;renderJob(data);if(['resume','retry'].includes(action)){automatic=true;advance();}}catch(e){if(selection===jobSelection)message(e.message,true);}});
  $('[data-job-action="pause"]').after(node('button',T('stop_import'),{type:'button',class:'ax-btn','data-job-action':'cancel'}));
  $('#avdb-result-state').on('change',function(){itemPage=1;loadItems(true);});
  $('#avdb-refresh-results').on('click',function(){loadItems(true);});
  $('#avdb-items-prev').on('click',function(){itemPage=Math.max(1,itemPage-1);loadItems(true);});
  $('#avdb-items-next').on('click',function(){itemPage++;loadItems(true);});
  $('#avdb-job-history').on('change',async function(){if(!this.value)return;var requestedJob=this.value,selection=++jobSelection;stopLoop();clearTimeout(liveTimer);try{var data=await request('status',{job_id:requestedJob});if(selection===jobSelection)renderJob(data);}catch(e){if(selection===jobSelection)message(e.message,true);}});$('#avdb-refresh-jobs').on('click',loadJobs);
  $('#avdb-undo').on('click',function(){xvUndoOpen();document.getElementById('avdb-undo-dialog').showModal();});$('#avdb-cancel-undo').on('click',function(){document.getElementById('avdb-undo-dialog').close();});$('#avdb-confirm-undo').on('click',function(){document.getElementById('avdb-undo-dialog').close();stopLoop();xvUndoRun();});
  $('#avdb-save-preset').on('click',function(){document.getElementById('avdb-preset-dialog').showModal();});$('#avdb-cancel-preset').on('click',function(){document.getElementById('avdb-preset-dialog').close();});
  $('#avdb-preset-form').on('submit',function(e){e.preventDefault();var name=String(values(this).preset_name || '').trim();if(!name)return;presets.push({name:name,filters:readFilters(values('#avdb-filter-form'))});presets=presets.slice(-20);try{localStorage.setItem(presetKey,JSON.stringify(presets));populatePresets();document.getElementById('avdb-preset-dialog').close();message(T('preset_saved'));}catch(error){message(T('preset_failed'),true);}});
  $('#avdb-preset').on('change',function(){var data=this.value==='today'?{h:'24'}:this.value==='week'?{h:'168'}:this.value.indexOf('saved-')===0&&presets[Number(this.value.slice(6))]?presets[Number(this.value.slice(6))].filters:null;if(!data)return;document.getElementById('avdb-filter-form').reset();closeSuggestions();Object.keys(readFilters(data)).forEach(function(key){$('#avdb-filter-form [name="'+key+'"]').val(data[key]);});loadPreview(1);});
  try{var stored=JSON.parse(localStorage.getItem(presetKey)||'[]');presets=Array.isArray(stored)?stored.filter(function(p){return p&&typeof p.name==='string'&&p.filters&&typeof p.filters==='object';}).slice(-20):[];}catch(e){presets=[];}populatePresets();initializeSuggestions();xvInit();var scheduleReady=scheduleAction('scheduleStatus');
  // Beginner r7 P2-1: links with ?section=schedule (Overview checklist, attention row, menu search) open the
  // "Automatic imports" panel directly: Review step, panel scrolled into view, the on/off switch focused (after the
  // schedule status has loaded: the switch is disabled while it loads and cannot take the focus before).
  try {
   if (/(?:^|[?&])section=schedule(?:&|$)/.test(String(window.location.search || ''))) {
    panel('review');
    var jumpToSchedule = function () {
     var schedulePanel = document.querySelector('.avdb-schedule'), scheduleSwitch = document.getElementById('avdb-schedule-enabled');
     if (schedulePanel) schedulePanel.scrollIntoView({block: 'start'});
     if (scheduleSwitch && !scheduleSwitch.disabled) scheduleSwitch.focus({preventScroll: true});
    };
    jumpToSchedule();
    Promise.resolve(scheduleReady).then(function () { setTimeout(jumpToSchedule, 0); }, function () { setTimeout(jumpToSchedule, 0); });
   }
  } catch (e) { /* the page still works without the jump */ }
 });
})(typeof window === 'undefined' ? {} : window);
