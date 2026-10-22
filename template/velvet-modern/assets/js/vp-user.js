/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
/*
 * Velvet account pages: one small handler set driven by data attributes instead of inline scripts.
 * All writes go through jQuery $.ajax so static/template-ui/secure-forms.js can add the session token.
 */
(function (window, document, $) {
  'use strict';
  if (!$) return;
  var maccms = window.maccms || {};
  var routes = maccms.template_urls || {};
  function msg(key, fallback) { var m = window.TemplateMessages || {}; return typeof m[key] === 'string' && m[key] ? m[key] : (fallback || ''); }
  function fill(key, values) { return window.galleryMessage ? window.galleryMessage(key, values) : msg(key); }
  function toast(text, kind) { if (!text) return; if (window.TemplateUI && TemplateUI.message) TemplateUI.message(String(text), { kind: kind || 'info' }); }
  function confirmBox(text) { return window.TemplateUI && TemplateUI.confirm ? TemplateUI.confirm(text) : Promise.resolve(window.confirm(text)); }
  function route(el, attr) {
    var key = el.getAttribute(attr + '-key');
    if (key && routes[key]) return routes[key];
    return el.getAttribute(attr) || '';
  }
  function safeUrl(url) {
    try { var u = new URL(url, location.href); return u.origin === location.origin ? u.href : ''; } catch (e) { return ''; }
  }
  function refreshCaptcha(scope) {
    var images = (scope || document).querySelectorAll('[data-vp-captcha]');
    for (var i = 0; i < images.length; i++) {
      var img = images[i];
      try { var u = new URL(img.getAttribute('data-src') || img.src, location.href); u.searchParams.set('r', Date.now()); img.src = u.href; } catch (e) {}
    }
  }
  function setBusy(button, busy) {
    if (!button) return;
    if (busy) { button.setAttribute('data-label', button.textContent); button.textContent = msg('ui.loading', '...'); button.disabled = true; }
    else { if (button.hasAttribute('data-label')) button.textContent = button.getAttribute('data-label'); button.disabled = false; }
  }

  // Captcha images are created only when the code field is focused (rate limits), then refresh on click.
  function ensureCaptcha(input) {
    var scope = input.form || document;
    var slot = scope.querySelector('[data-vp-captcha-src]');
    if (!slot || slot.querySelector('img')) return;
    var img = document.createElement('img');
    img.className = 'vp-captcha';
    img.alt = msg('verification_code', '');
    img.title = msg('not_to_see_change_one', '');
    img.width = 120;
    img.height = 44;
    img.tabIndex = 0;
    img.setAttribute('role', 'button');
    img.setAttribute('data-vp-captcha', '');
    img.setAttribute('data-src', slot.getAttribute('data-vp-captcha-src'));
    img.src = slot.getAttribute('data-vp-captcha-src');
    slot.appendChild(img);
  }
  $(document).on('focus', '[data-vp-captcha-input]', function () { ensureCaptcha(this); });
  $(document).on('click keydown', '[data-vp-captcha]', function (event) {
    if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') return;
    refreshCaptcha(this.parentNode);
  });

  // Generic AJAX form: data-vp-form, data-url(-key), data-success (url or "reload"), optional data-confirm.
  $(document).on('submit', 'form[data-vp-form]', function (event) {
    event.preventDefault();
    var form = this;
    var fields = form.querySelectorAll('[data-required]');
    for (var i = 0; i < fields.length; i++) {
      if (!String(fields[i].value || '').trim()) { toast(msg(fields[i].getAttribute('data-required')), 'error'); fields[i].focus(); return; }
    }
    var matches = form.querySelectorAll('[data-match]');
    for (var j = 0; j < matches.length; j++) {
      var other = form.querySelector(matches[j].getAttribute('data-match'));
      if (other && other.value !== matches[j].value) { toast(msg('ui.the_second_password_is_inconsistent'), 'error'); matches[j].focus(); return; }
    }
    var url = route(form, 'data-url');
    if (!safeUrl(url)) return;
    var button = form.querySelector('[type="submit"]');
    var run = function () {
      setBusy(button, true);
      $.ajax({ url: url, type: 'post', dataType: 'json', data: $(form).serialize() })
        .done(function (r) {
          var ok = r && Number(r.code) === 1;
          toast(r && r.msg ? r.msg : '', ok ? 'success' : 'error');
          if (ok) {
            var next = form.getAttribute('data-success');
            if (r.data && r.data.order_code && form.getAttribute('data-pay')) next = form.getAttribute('data-pay') + (form.getAttribute('data-pay').indexOf('?') < 0 ? '?' : '&') + 'order_code=' + encodeURIComponent(r.data.order_code);
            if (next === 'reload') { window.setTimeout(function () { location.reload(); }, 600); }
            else if (next && safeUrl(next)) { window.setTimeout(function () { location.href = safeUrl(next); }, 600); }
          } else {
            refreshCaptcha(form);
          }
        })
        .fail(function () { toast(msg('ui.connection_failed_try_again'), 'error'); })
        .always(function () { setBusy(button, false); });
    };
    var ask = form.getAttribute('data-confirm');
    if (ask) confirmBox(msg(ask)).then(function (yes) { if (yes) run(); }); else run();
  });

  // Send verification code (register / bind / recover): validates the address, then counts down.
  $(document).on('click', '[data-vp-sendcode]', function () {
    var button = this;
    var form = button.form || button.closest('form');
    if (!form || button.disabled) return;
    var acInput = form.querySelector('[name="ac"]');
    var toInput = form.querySelector('[name="to"]');
    var ac = acInput ? acInput.value : '';
    var to = toInput ? toInput.value.trim() : '';
    if (!to) { toast(msg('ui.enter_email_or_phone'), 'error'); if (toInput) toInput.focus(); return; }
    if (ac === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(to)) { toast(msg('ui.email_format_is_incorrect'), 'error'); return; }
    if (ac === 'phone' && !/^\+?[0-9][0-9 \-]{5,19}$/.test(to)) { toast(msg('ui.incorrect_mobile_phone_number_format'), 'error'); return; }
    var url = route(button, 'data-url');
    if (!safeUrl(url)) return;
    var label = button.textContent, left = 60;
    button.disabled = true;
    var timer = window.setInterval(function () {
      left -= 1;
      if (left <= 0) { window.clearInterval(timer); button.disabled = false; button.textContent = label; return; }
      button.textContent = fill('ui.resend_verification_countdown', { seconds: left });
    }, 1000);
    button.textContent = fill('ui.resend_verification_countdown', { seconds: left });
    $.ajax({ url: url, type: 'post', dataType: 'json', data: $(form).serialize() })
      .done(function (r) { toast(r && r.msg ? r.msg : '', r && Number(r.code) === 1 ? 'success' : 'error'); if (!r || Number(r.code) !== 1) refreshCaptcha(form); })
      .fail(function () { toast(msg('ui.connection_failed_try_again'), 'error'); });
  });

  // Record lists: select all / invert / delete selected / clear all.
  function recordIds(box) {
    var ids = [];
    box.querySelectorAll('input[name="ids[]"]:checked').forEach(function (input) { ids.push(input.value); });
    return ids.join(',');
  }
  function deleteRecords(box, ids, all) {
    var url = route(box, 'data-url');
    if (!safeUrl(url)) return;
    confirmBox(msg(all ? 'ui.confirm_clear_records' : 'ui.confirm_delete_selected_records')).then(function (yes) {
      if (!yes) return;
      var data = { ids: ids, all: all ? 1 : 0 };
      if (box.getAttribute('data-type')) data.type = box.getAttribute('data-type');
      $.ajax({ url: url, type: 'post', dataType: 'json', data: data })
        .done(function (r) {
          if (r && Number(r.code) === 1) { toast(msg('ui.records_deleted'), 'success'); window.setTimeout(function () { location.reload(); }, 500); }
          else toast(fill('ui.delete_failed_reason', { reason: r && r.msg ? r.msg : '' }), 'error');
        })
        .fail(function () { toast(msg('ui.connection_failed_try_again'), 'error'); });
    });
  }
  $(document).on('click', '[data-vp-records] [data-vp-act]', function () {
    var box = this.closest('[data-vp-records]');
    var act = this.getAttribute('data-vp-act');
    var boxes = box.querySelectorAll('input[name="ids[]"]');
    if (act === 'all') boxes.forEach(function (b) { b.checked = true; });
    else if (act === 'invert') boxes.forEach(function (b) { b.checked = !b.checked; });
    else if (act === 'clear') deleteRecords(box, '', true);
    else if (act === 'delete') {
      var single = this.getAttribute('data-id');
      var ids = single || recordIds(box);
      if (!ids) { toast(msg('ui.please_select_at_least_one_data'), 'error'); return; }
      deleteRecords(box, ids, false);
    }
  });

  // Membership upgrade buttons.
  $(document).on('click', '[data-vp-upgrade]', function () {
    var button = this;
    var url = routes.user_upgrade || '';
    if (!safeUrl(url)) return;
    confirmBox(fill('ui.confirm_upgrade_membership', { name: button.getAttribute('data-name') || '', points: button.getAttribute('data-points') || '' })).then(function (yes) {
      if (!yes) return;
      $.ajax({ url: url, type: 'post', dataType: 'json', data: { group_id: button.getAttribute('data-id'), long: button.getAttribute('data-long') } })
        .done(function (r) { toast(r && r.msg ? r.msg : '', r && Number(r.code) === 1 ? 'success' : 'error'); if (r && Number(r.code) === 1) window.setTimeout(function () { location.reload(); }, 700); })
        .fail(function () { toast(msg('ui.connection_failed_try_again'), 'error'); });
    });
  });

  // Unbind email or phone.
  $(document).on('click', '[data-vp-unbind]', function () {
    var ac = this.getAttribute('data-vp-unbind');
    if (ac !== 'email' && ac !== 'phone') { toast(msg('ui.parameter_error'), 'error'); return; }
    var url = routes.user_unbind || '';
    if (!safeUrl(url)) return;
    confirmBox(msg('ui.confirm_the_lifting_binding_this_operation_cannot_be_restored')).then(function (yes) {
      if (!yes) return;
      $.ajax({ url: url, type: 'post', dataType: 'json', data: { ac: ac } })
        .done(function (r) { toast(r && r.msg ? r.msg : '', r && Number(r.code) === 1 ? 'success' : 'error'); if (r && Number(r.code) === 1) window.setTimeout(function () { location.reload(); }, 700); })
        .fail(function () { toast(msg('ui.connection_failed_try_again'), 'error'); });
    });
  });

  // Order status polling on the QR payment page (every 5 s, stops after 10 minutes).
  var poll = document.querySelector('[data-vp-order-poll]');
  if (poll) {
    var started = Date.now();
    var timer = window.setInterval(function () {
      if (Date.now() - started > 600000) { window.clearInterval(timer); return; }
      $.ajax({ url: poll.getAttribute('data-vp-order-poll'), dataType: 'json' }).done(function (data) {
        if (data && data.info && Number(data.info.order_status) === 1) {
          window.clearInterval(timer);
          toast(msg('ui.payment_completed_redirecting'), 'success');
          var next = safeUrl(poll.getAttribute('data-success') || '');
          if (next) location.href = next;
        }
      });
    }, 5000);
  }
})(window, document, window.jQuery);
