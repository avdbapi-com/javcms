/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
(function () {
 'use strict';
 function init() {
  var page = document.getElementById('admin-sms-settings');
  var formElement = document.getElementById('admin-sms-form');
  if (!page || !formElement || page.dataset.smsBound) return;
  page.dataset.smsBound = '1';
  window.layui.use(['element','form'],function () {
   var element = window.layui.element, form = window.layui.form;
   form.on('select(sms-provider)',function (event) {
    var value = String(event.value).toLowerCase();
    var tabs = page.querySelectorAll('.layui-tab-title [data-key]');
    for (var i=0;i<tabs.length;i++) {
     if (tabs[i].dataset.key.toLowerCase() === value) {
      element.tabChange('sms-settings-tabs',tabs[i].getAttribute('lay-id'));
      return;
     }
    }
   });
   formElement.addEventListener('reset',function () { window.setTimeout(function () { form.render(); },0); });
  });
  // The existing formSubmit callback owns saving. Keep its refreshed token valid
  // after a native Reset too; this hook never initiates requests or saves.
  window.jQuery(document).on('ajaxSuccess.adminSmsSettings',function (event,xhr,settings,response) {
   if (String(settings.type || '').toUpperCase() !== 'POST') return;
   var requestUrl = new URL(settings.url || window.location.href,window.location.href);
   var formUrl = new URL(formElement.action || window.location.href,window.location.href);
   if (requestUrl.origin !== formUrl.origin || requestUrl.pathname !== formUrl.pathname || requestUrl.search !== formUrl.search) return;
   var token = response && (response.__token__ || response.data && response.data.__token__);
   var input = formElement.querySelector('input[name="__token__"]');
   if (token && input) { input.value = token; input.defaultValue = token; }
  });
 }
 if (document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
}());
