/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
(function () {
 'use strict';
 function init() {
  var page = document.getElementById('admin-multisite');
  if (!page || page.dataset.bound) return;
  page.dataset.bound = '1';
  var status = page.querySelector('#multisite-status'), pending = false;
  var tabs = Array.prototype.slice.call(page.querySelectorAll('[role="tab"]'));
  function activate(tab) {
   tabs.forEach(function (item) {
    var selected = item === tab;
    item.setAttribute('aria-selected', String(selected)); item.tabIndex = selected ? 0 : -1;
    document.getElementById(item.getAttribute('aria-controls')).hidden = !selected;
   });
  }
  tabs.forEach(function (tab, index) {
   tab.addEventListener('click', function () { activate(tab); });
   tab.addEventListener('keydown', function (event) {
    var next = event.key === 'ArrowRight' ? (index + 1) % tabs.length : event.key === 'ArrowLeft' ? (index + tabs.length - 1) % tabs.length : event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : -1;
    if (next >= 0) { event.preventDefault(); activate(tabs[next]); tabs[next].focus(); }
   });
  });
  function refreshToken(response) {
   var token = response && (response.__token__ || (response.data && response.data.__token__));
   if (!token) return;
   page.querySelectorAll('input[name="__token__"]').forEach(function (input) { input.value = token; input.defaultValue = token; });
  }
  // F48: highlight the row the server named (data.row, 1-based) and put the cursor in its address field.
  function markRow(response, multipart) {
   page.querySelectorAll('.multisite-row.is-error').forEach(function (row) { row.classList.remove('is-error'); });
   var n = response && response.data ? parseInt(response.data.row, 10) : 0;
   if (multipart || !n) return;
   var row = page.querySelectorAll('#domain_list .multisite-row')[n - 1];
   if (!row) return;
   row.classList.add('is-error');
   var input = row.querySelector('input[name="domain[site_url][]"]');
   if (input) { input.setAttribute('aria-invalid', 'true'); input.focus(); input.select(); input.addEventListener('input', function clear() { row.classList.remove('is-error'); input.removeAttribute('aria-invalid'); input.removeEventListener('input', clear); }); }
   else { row.scrollIntoView({block: 'center'}); }
  }
  function send(url, data, multipart) {
   if (pending) return;
   pending = true; page.setAttribute('aria-busy','true'); status.textContent = page.dataset.loading;
   var buttons = Array.prototype.slice.call(page.querySelectorAll('button'));
   var states = buttons.map(function (button) { return button.disabled; });
   buttons.forEach(function (button) { button.disabled = true; });
   window.jQuery.ajax({url:url,type:'POST',dataType:'json',data:data,processData:!multipart,contentType:multipart ? false : 'application/x-www-form-urlencoded; charset=UTF-8',timeout:30000,
    success:function (response) { refreshToken(response); status.textContent = response && typeof response.msg === 'string' ? response.msg : page.dataset.error; if (response && response.code === 1) window.location.reload(); else markRow(response, multipart); },
    error:function () { status.textContent = page.dataset.error; },
    complete:function () { pending=false;page.removeAttribute('aria-busy');buttons.forEach(function (button,index) { button.disabled=states[index]; }); }
   });
  }
  page.querySelector('#multisite-save-form').addEventListener('submit',function (event) { event.preventDefault(); if (!pending) send(this.action,window.jQuery(this).serialize(),false); });
  page.querySelector('#multisite-import-form').addEventListener('submit',function (event) { event.preventDefault(); if (!pending && window.confirm(page.dataset.confirmImport)) send(this.action,new FormData(this),true); });
  page.querySelector('#multisite-add').addEventListener('click',function () {
   if (pending) return;
   var fragment = document.getElementById('multisite-new-row').content.cloneNode(true);
   var row = fragment.querySelector('.multisite-row');
   page.querySelector('#domain_list').appendChild(fragment); row.querySelector('input[type="text"]').focus();
  });
  page.addEventListener('click',function (event) {
   var remove = event.target.closest('.multisite-remove');
   if (remove && !pending) { remove.closest('.multisite-row').remove(); page.querySelector('#multisite-add').focus(); }
   var deletion = event.target.closest('.multisite-delete');
   if (deletion && !pending && window.confirm(page.dataset.confirmDelete)) {
    send(page.dataset.deleteUrl,{ids:deletion.dataset.domainId,__token__:page.querySelector('input[name="__token__"]').value},false);
   }
  });
  page.querySelector('#multisite-save-form').addEventListener('reset',function () { page.querySelectorAll('.multisite-row[data-new="1"]').forEach(function (row) { row.remove(); }); });
 }
 if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',init); else init();
}());
