/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
'use strict';
document.addEventListener('DOMContentLoaded', function () {
  var menu = document.querySelector('.menu-toggle');
  var navigation = document.getElementById('gallery-navigation');
  if (menu && navigation) {
    menu.addEventListener('click', function () {
      var expanded = menu.getAttribute('aria-expanded') === 'true';
      menu.setAttribute('aria-expanded', String(!expanded));
      navigation.classList.toggle('is-open', !expanded);
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !document.querySelector('.tui-dialog,.gallery-history[open],.gallery-qr[open]') && menu.getAttribute('aria-expanded') === 'true') {
        menu.setAttribute('aria-expanded', 'false');
        navigation.classList.remove('is-open');
        menu.focus();
      }
    });
  }
  document.querySelectorAll('img').forEach(function (image) {
    // Content thumbnails can also appear on actor/topic detail pages outside an
    // `.item` card. Keep the provider URL until it actually errors, then use
    // the theme placeholder. Header, captcha, QR and avatar images remain
    // covered by their dedicated selectors below.
    if (!image.matches('.item img,.main-img>img,.thumb,.thumb_img,.gallery-brand-image,.face,.model-info img') || image.closest('.jc-thumb,.jc-cover')) return;
    function showFallback() {
      if (image.dataset.fallbackApplied) return;
      image.dataset.fallbackApplied = 'true';
      image.src = image.classList.contains('face') ? (window.maccms && window.maccms.templatePath || '/') + 'ksassets/image/portrait-placeholder.svg' : image.classList.contains('gallery-brand-image') ? (window.maccms && window.maccms.path || '') + '/static/template-ui/brand-javcms.svg' : (window.maccms && window.maccms.templatePath || '/') + 'ksassets/image/poster-placeholder.svg';
    }
    image.addEventListener('error', showFallback);
    var currentSource = image.getAttribute('src') || '';
    if (/\/nopic\.gif(?:[?#]|$)/i.test(currentSource)) showFallback();
    // Lazy images can report complete before their request is started. Keep
    // the provider URL until the browser emits a real load error.
    else if (image.complete && !image.naturalWidth && image.getAttribute('loading') !== 'lazy') showFallback();
  });
  document.querySelectorAll('.gallery-avatar-form').forEach(function (form) {
    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      if (form.getAttribute('aria-busy') === 'true') return;
      var input = form.querySelector('input[type="file"]');
      var button = form.querySelector('button[type="submit"]');
      var status = form.querySelector('.gallery-avatar-status');
      var labels = window.TemplateMessages || {};
      function report(text, kind) { status.textContent = text; if (window.TemplateUI) TemplateUI.message(text, {kind: kind || 'info'}); }
      if (!input.files.length) { report(labels['select_avatar_file'], 'error'); input.focus(); return; }
      if (!/^image\/(jpeg|png|gif|webp)$/.test(input.files[0].type)) { report(labels['avatar_upload_failed'], 'error'); input.focus(); return; }
      var endpoint = new URL(form.action, location.href);
      if (endpoint.origin !== location.origin) return;
      button.disabled = true;
      form.setAttribute('aria-busy', 'true');
      status.textContent = labels['template_ui_working'];
      try {
        var response = await fetch(endpoint.href, {method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}});
        if (!response.ok) throw new Error('upload');
        var result = await response.json();
        if (result && typeof result.__token__ === 'string') document.querySelectorAll('input[name="__token__"]').forEach(function (token) { token.value = result.__token__; });
        if (Number(result.code) !== 1) { report(typeof result.msg === 'string' ? result.msg : labels['ui.connection_failed_try_again'], 'error'); return; }
        report(labels['avatar_updated'], 'success');
        window.location.reload();
      } catch (_) { report(labels['ui.connection_failed_try_again'], 'error'); }
      finally { button.disabled = false; form.removeAttribute('aria-busy'); }
    });
  });
  var historyPanel = document.querySelector('.gallery-history');
  function renderHistory() {
    if (!historyPanel || !window.MAC || !MAC.History) return;
    var list = historyPanel.querySelector('.gallery-history-list');
    list.replaceChildren();
    var rows = MAC.History.Read();
    rows.forEach(function (row) {
      var item = document.createElement('li');
      var link = document.createElement('a');
      link.href = row.link;
      link.textContent = row.name;
      item.appendChild(link);
      list.appendChild(item);
    });
    if (!rows.length) {
      var empty = document.createElement('li');
      empty.textContent = (window.TemplateMessages || {}).no_history || '';
      list.appendChild(empty);
    }
  }
  var historyEntry = document.querySelector('.gallery-history-entry');
  if (historyEntry && window.MAC && MAC.History) MAC.History.Set(historyEntry.dataset.name, historyEntry.dataset.link, historyEntry.dataset.pic);
  if (historyPanel) {
    historyPanel.addEventListener('toggle', renderHistory);
    historyPanel.querySelector('.gallery-history-clear').addEventListener('click', function () {
      if (window.MAC && MAC.History) MAC.History.Clear();
      renderHistory();
    });
  }
  var qrPanel = document.querySelector('.gallery-qr');
  if (qrPanel) {
    qrPanel.querySelector('#gallery-page-address').value = location.href;
    qrPanel.addEventListener('toggle', function () {
      if (qrPanel.open && window.MacLocalQrCode) MacLocalQrCode.renderImage(qrPanel.querySelector('.gallery-qr-image'), location.href, 180);
    });
  }
  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape' || document.querySelector('.tui-dialog')) return;
    var disclosure = document.querySelector('.gallery-history[open],.gallery-qr[open]');
    if (disclosure) { disclosure.open = false; disclosure.querySelector('summary').focus(); }
  });
  document.querySelectorAll('.link-tab').forEach(function (tab) {
    var href = tab.getAttribute('href');
    if (!href || href.charAt(0) !== '#') return;
    tab.addEventListener('click', function (event) {
      var target = document.getElementById(href.slice(1));
      if (!target) return;
      event.preventDefault();
      document.querySelectorAll('.tab-content').forEach(function (panel) { panel.style.display = 'none'; });
      document.querySelectorAll('.link-tab').forEach(function (item) { item.classList.remove('active'); item.removeAttribute('aria-current'); });
      target.style.display = 'block';
      tab.classList.add('active');
      tab.setAttribute('aria-current', 'true');
    });
  });
  function message(text) {
    if (window.TemplateUI) { TemplateUI.message(text); return; }
    var status = document.getElementById('gallery-status');
    if (!status) return;
    status.textContent = text;
    status.classList.add('is-visible');
    window.setTimeout(function () { status.classList.remove('is-visible'); }, 4000);
  }
  document.querySelectorAll('[data-copy-target]').forEach(function (button) {
    button.addEventListener('click', function () {
      var input = document.getElementById(button.dataset.copyTarget);
      if (!input) return;
      var labels = window.galleryText || {};
      function fallback() {
        input.focus(); input.select();
        try { message(document.execCommand('copy') ? labels.copied || 'Link copied' : labels.select || 'Select and copy the address'); }
        catch (_) { message(labels.select || 'Select and copy the address'); }
      }
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(input.value).then(function () { message(labels.copied || 'Link copied'); }, fallback);
      else fallback();
    });
  });
  if (window.jQuery) window.jQuery(document).ajaxError(function (_event, _request, settings) {
    if (/\/user\/(?:login|reg|bind|info|findpass|buy|upgrade|cash)/.test(settings.url || '') && window.TemplateUI) TemplateUI.message((window.galleryText || {}).network || '', {kind: 'error'});
  });
  var modalLogin = document.getElementById('gallery-modal-login');
  if (modalLogin && window.jQuery) modalLogin.addEventListener('submit', function (event) {
    event.preventDefault();
    var submit = modalLogin.querySelector('button[type="submit"]');
    submit.disabled = true;
    window.jQuery.ajax({url: modalLogin.action, type: 'post', dataType: 'json', data: window.jQuery(modalLogin).serialize()})
      .done(function (result) { if (result.code === 1) window.location.reload(); else modalLogin.querySelector('.login-status').textContent = result.msg; })
      .fail(function () { modalLogin.querySelector('.login-status').textContent = (window.galleryText || {}).network || 'Connection failed. Try again.'; })
      .always(function () { submit.disabled = false; });
  });
;
});
