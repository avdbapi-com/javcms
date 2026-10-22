/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
/* Whole-catalog cleanup dialog; authenticated bounded POST requests, no shared admin handlers. */
(function () {
  'use strict';
  var configNode = document.getElementById('vod-cleanup-config');
  var trigger = document.getElementById('vod-cleanup-trigger');
  if (!configNode || !trigger || trigger.dataset.cleanupBound === '1') return;
  var text;
  try { text = JSON.parse(configNode.textContent); } catch (_) { return; }
  trigger.dataset.cleanupBound = '1';
  layui.use(['layer'], function () {
    var $ = layui.jquery, layer = layui.layer;
    var dialog = null, layerIndex = null, requestBusy = false, timer = null, generation = 0;
    var job = null, preview = null, advancing = false, requestedControl = null, initiating = false;
    function t(key) { return text[key] || key; }
    function token() { return $('#pageListForm input[name="__token__"]').val(); }
    function node(tag, className, value) { return $('<' + tag + '>', { 'class':className || '' }).text(value || ''); }
    function live() { return dialog !== null && layerIndex !== null; }
    function stop() { advancing = false; clearTimeout(timer); timer = null; }
    function error(message) { if (live()) dialog.find('.vod-cleanup-error').text(message).prop('hidden', false); }
    function clearError() { if (live()) dialog.find('.vod-cleanup-error').empty().prop('hidden', true); }
    function number(value) { return Number(value || 0).toLocaleString(); }
    function busy(value) {
      requestBusy = value;
      if (!live()) return;
      dialog.find('.vod-cleanup-start').prop('disabled', value || !preview || !preview.ticket || dialog.find('.vod-cleanup-phrase').val() !== 'DELETE ALL VIDEOS');
      dialog.find('.vod-cleanup-refresh').prop('disabled', value);
      dialog.find('.vod-cleanup-resume').prop('disabled', value || !job || !['running','paused'].includes(job.state));
      dialog.find('.vod-cleanup-pause').prop('disabled', !job || job.state !== 'running' || requestedControl !== null);
      dialog.find('.vod-cleanup-cancel-job').prop('disabled', !job || !['running','paused'].includes(job.state) || requestedControl !== null);
    }
    function request(operation, values) {
      if (requestBusy) return Promise.reject(new Error(t('busy')));
      busy(true);
      return new Promise(function (resolve, reject) {
        $.ajax({url:trigger.dataset.href, type:'POST', dataType:'json', timeout:60000,
          data:Object.assign({operation:'cleanup_' + operation, __token__:token()}, values || {})
        }).done(function (reply) {
          if (!reply || reply.code != 1 || !reply.data || typeof reply.data !== 'object') {
            reject(new Error(reply && typeof reply.msg === 'string' ? reply.msg : t('failed'))); return;
          }
          resolve(reply.data);
        }).fail(function () { var failure = new Error(t('transport')); failure.transport = true; reject(failure); })
          .always(function () { busy(false); });
      });
    }
    function render(data) {
      var identifier = data && (data.job_id || data.id);
      if (!/^[a-f0-9]{32}$/.test(identifier || '') || !['running','paused','cancelled','complete'].includes(data.state) || data.images_kept !== true || data.irreversible !== true ||
          ['total_videos','deleted_videos','deleted_relations','version'].some(function(key){return !Number.isSafeInteger(data[key]) || data[key] < 0;}) ||
          job && (job.job_id || job.id) !== identifier) throw new Error(t('failed'));
      job = data; preview = null;
      if (!live()) return;
      initiating = false;
      dialog.find('.vod-cleanup-preview').prop('hidden', true);
      dialog.find('.vod-cleanup-progress').prop('hidden', false);
      dialog.find('.vod-cleanup-status').text(t(data.state));
      dialog.find('.vod-cleanup-count').text(t('deleted') + ': ' + number(data.deleted_videos) + ' / ' + number(data.total_videos));
      dialog.find('.vod-cleanup-relations').text(t('relations') + ': ' + number(data.deleted_relations));
      var total = Number(data.total_videos || 0), deleted = Number(data.deleted_videos || 0);
      dialog.find('progress').attr('max', Math.max(1, total)).val(deleted);
      dialog.find('.vod-cleanup-newer').text(data.newer_videos_remaining === null || data.newer_videos_remaining === undefined ? '' : t('newer') + ': ' + number(data.newer_videos_remaining));
      dialog.find('.vod-cleanup-resume').prop('hidden', !['running','paused'].includes(data.state) || advancing);
      dialog.find('.vod-cleanup-pause').prop('hidden', data.state !== 'running' || !advancing);
      dialog.find('.vod-cleanup-cancel-job').prop('hidden', !['running','paused'].includes(data.state));
      dialog.find('.vod-cleanup-reload').prop('hidden', !['complete','cancelled'].includes(data.state));
      if (data.error) error(data.error);
      if (!['running','paused'].includes(data.state)) stop();
      busy(requestBusy);
    }
    function jobValues() { return {job_id:job.job_id || job.id}; }
    async function recover(currentGeneration) {
      stop();
      try {
        // Preview is read-only and finds this owner's saved job even if the start reply was lost.
        var data = job ? await request('status', jobValues()) : await request('preview');
        if (!live() || generation !== currentGeneration) return;
        if (job) render(data);
        else if (data.active_job) render(data.active_job);
        else { showPreview(data); }
        error(t('recovered'));
      } catch (failure) { if (generation === currentGeneration) error(failure.message); }
    }
    async function run(currentGeneration) {
      if (!live() || generation !== currentGeneration || !advancing || !job || job.state !== 'running') return;
      try {
        var operation = requestedControl || 'step'; requestedControl = null;
        var values = jobValues();
        if (operation === 'step') values.version = job.version;
        var data = await request(operation, values);
        if (!live() || generation !== currentGeneration) { stop(); return; }
        if (operation !== 'step') stop();
        render(data);
        if (advancing && job.state === 'running') timer = setTimeout(function () { run(currentGeneration); }, 300);
      } catch (failure) {
        stop(); error(failure.message);
        if (failure.transport) await recover(currentGeneration);
      }
    }
    function showPreview(data) {
      if(!data || !data.counts || ['videos','active','recycled','upper_id'].some(function(key){return !Number.isSafeInteger(data.counts[key]) || data.counts[key] < 0;}) ||
          data.counts.active + data.counts.recycled !== data.counts.videos || data.images_kept !== true || data.irreversible !== true) throw new Error(t('failed'));
      preview = data; job = null; initiating = false;
      if (!live()) return;
      dialog.find('.vod-cleanup-progress').prop('hidden', true);
      dialog.find('.vod-cleanup-preview').prop('hidden', false);
      dialog.find('.vod-cleanup-summary').text(t('active') + ': ' + number(data.counts && data.counts.active) + ' · ' + t('recycled') + ': ' + number(data.counts && data.counts.recycled) + ' · ' + t('total') + ': ' + number(data.counts && data.counts.videos));
      dialog.find('.vod-cleanup-phrase').val('');
      dialog.find('.vod-cleanup-empty').prop('hidden', Number(data.counts && data.counts.videos) !== 0).text(t('empty'));
      if (data.busy) { preview.ticket = null; error(t('busy')); }
      if (!data.counts || Number(data.counts.videos) < 1) preview.ticket = null;
      busy(false);
    }
    async function loadPreview() {
      var currentGeneration = generation; clearError(); preview = null; busy(requestBusy);
      dialog.find('.vod-cleanup-summary').text(t('loading'));
      try {
        var data = await request('preview');
        if (!live() || generation !== currentGeneration) return;
        if (data.active_job) render(data.active_job); else showPreview(data);
      } catch (failure) { if (generation === currentGeneration) error(failure.message); }
    }
    function control(operation) {
      if (!job || !['running','paused'].includes(job.state)) return;
      clearError();
      if (requestBusy) { requestedControl = operation; busy(true); return; }
      requestedControl = operation; advancing = true; run(generation);
    }
    function open() {
      if (live()) return;
      generation++; stop(); job = null; preview = null; requestedControl = null;
      dialog = node('div', 'vod-cleanup-dialog');
      dialog.append(node('p', '', t('scope')), node('p', '', t('files')), node('p', '', t('imports')), node('p','vod-cleanup-irreversible',t('irreversible')));
      dialog.append(node('p','vod-cleanup-error').attr('role','alert').prop('hidden',true));
      var before = node('section','vod-cleanup-preview');
      before.append(node('p','vod-cleanup-summary',t('loading')).attr('role','status'),node('p','vod-cleanup-empty').prop('hidden',true));
      before.append(node('label','',t('typed')).attr('for','vod-cleanup-phrase'));
      before.append($('<input>',{id:'vod-cleanup-phrase','class':'layui-input vod-cleanup-phrase',type:'text',autocomplete:'off',spellcheck:'false','aria-describedby':'vod-cleanup-literal'}));
      before.append(node('p','vod-cleanup-literal','DELETE ALL VIDEOS').attr('id','vod-cleanup-literal'));
      var beforeActions = node('div','vod-cleanup-actions');
      beforeActions.append(node('button','btn btn-danger vod-cleanup-start',t('start')).attr('type','button').prop('disabled',true),node('button','btn btn-default vod-cleanup-refresh',t('refresh')).attr('type','button'));
      before.append(beforeActions); dialog.append(before);
      var after = node('section','vod-cleanup-progress').prop('hidden',true);
      after.append(node('p','vod-cleanup-status').attr('role','status'),node('p','vod-cleanup-count'),$('<progress>',{max:1,value:0,'aria-label':t('progress')}),node('p','vod-cleanup-relations'),node('p','vod-cleanup-newer'),node('p','',t('saved')));
      var afterActions = node('div','vod-cleanup-actions');
      [['pause','pause'],['resume','resume'],['cancel-job','cancel_remaining'],['reload','reload']].forEach(function(pair){afterActions.append(node('button','btn btn-default vod-cleanup-' + pair[0],t(pair[1])).attr('type','button'));});
      after.append(afterActions); dialog.append(after);
      dialog.append(node('button','btn btn-default vod-cleanup-close',t('close')).attr('type','button'));
      // Layui wraps object content in place; detached content would leave only a shade.
      dialog.appendTo(document.body);
      layerIndex = layer.open({type:1,title:t('title'),content:dialog,area:[Math.min(560,window.innerWidth-24)+'px','auto'],shadeClose:false,closeBtn:0,success:function(){dialog.find('.vod-cleanup-close').focus();},end:function(){generation++;stop();$(document).off('keydown.vodCleanup');if(dialog)dialog.remove();dialog=null;layerIndex=null;trigger.focus();}});
      $(document).on('keydown.vodCleanup',function(event){
        if(!live())return;
        if(event.key==='Escape'){event.preventDefault();layer.close(layerIndex);return;}
        if(event.key!=='Tab')return;
        var controls=dialog.find('button,input').filter(':visible:not(:disabled)');if(!controls.length)return;
        var first=controls[0],last=controls[controls.length-1];
        if(event.shiftKey && document.activeElement===first){event.preventDefault();last.focus();}
        else if(!event.shiftKey && document.activeElement===last){event.preventDefault();first.focus();}
      });
      dialog.on('input','.vod-cleanup-phrase',function(){busy(requestBusy);});
      dialog.on('click','.vod-cleanup-close',function(){layer.close(layerIndex);});
      dialog.on('click','.vod-cleanup-refresh',loadPreview);
      dialog.on('click','.vod-cleanup-reload',function(){window.location.reload();});
      dialog.on('click','.vod-cleanup-pause',function(){control('pause');});
      dialog.on('click','.vod-cleanup-resume',async function(){
        if (requestBusy || !job) return;
        var currentGeneration=generation; clearError();
        try { var resumed=await request('resume',jobValues()); if(!live() || generation!==currentGeneration)return; render(resumed); if (job.state==='running') { advancing=true;render(job);run(currentGeneration); } }
        catch(failure){error(failure.message);if(failure.transport)await recover(currentGeneration);}
      });
      dialog.on('click','.vod-cleanup-cancel-job',function(){layer.confirm(t('cancel_confirm'),{title:t('title'),btn:[t('cancel_remaining'),t('close')]},function(index){layer.close(index);control('cancel');});});
      dialog.on('click','.vod-cleanup-start',async function(){
        if (requestBusy || initiating || !preview || !preview.ticket || dialog.find('.vod-cleanup-phrase').val()!=='DELETE ALL VIDEOS') return;
        var currentGeneration=generation;initiating=true;clearError();
        try { var started=await request('start',{ticket:preview.ticket,phrase:'DELETE ALL VIDEOS'}); if(!live() || generation!==currentGeneration)return; render(started); if(job.state==='running'){advancing=true;render(job);run(currentGeneration);} }
        catch(failure){initiating=false;preview=null;error(failure.message);busy(false);if(failure.transport)await recover(currentGeneration);}
      });
      loadPreview();
    }
    trigger.addEventListener('click',open);
  });
}());
