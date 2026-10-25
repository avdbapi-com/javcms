/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
/* Neon account pages: login, register, password recovery, profile, bindings, records, upgrade, card recharge, avatar.
   Uses jQuery $.ajax so static/template-ui/secure-forms.js can add the CSRF token to protected user-centre writes. */
(function (window, document, $) {
  'use strict';
  if (!$) { return; }
  var M = function (key, fallback) { var m = window.TemplateMessages || {}; return m[key] || fallback || key; };
  var say = function (text, kind) { if (window.TemplateUI) { window.TemplateUI.message(String(text == null ? '' : text), { kind: kind || 'info' }); } };
  var ask = function (text) { return window.TemplateUI && window.TemplateUI.confirm ? window.TemplateUI.confirm(text) : Promise.resolve(window.confirm(text)); };
  var fmt = function (key, values) { return window.galleryMessage ? window.galleryMessage(key, values) : M(key); };
  /* captcha images wait in data-src until the visitor focuses or taps the form (no captcha on page view) */
  var refreshCaptcha = function (scope) { $(scope || document).find('#verify_img, .nn-captcha').each(function () { if (this.nnWoke && Date.now() - this.nnWoke < 1000) { return; } var src = (this.getAttribute('data-src') || this.getAttribute('src') || '').split('?')[0]; this.removeAttribute('data-src'); this.setAttribute('src', src + '?' + Date.now()); }); };
  var wakeCaptcha = function () { $(this).find('img[data-src]').each(function () { var src = this.getAttribute('data-src').split('?')[0]; this.removeAttribute('data-src'); this.nnWoke = Date.now(); this.setAttribute('src', src + '?' + Date.now()); }); };
  var busy = function ($btn, on, label) {
    if (!$btn.length) { return; }
    var $txt = $btn.children('span').first(); if (!$txt.length) { $txt = $btn; }
    if (on) { $btn.data('nn-label', $btn.is('input') ? $btn.val() : $txt.text()); }
    var text = on ? M('ui.loading', 'Loading...') : (label || $btn.data('nn-label'));
    $btn.prop('disabled', !!on);
    if ($btn.is('input')) { $btn.val(text); } else { $txt.text(text); }
  };
  var need = function (selector, key, scope) {
    var $f = $(scope).find(selector);
    if ($f.length && !$.trim($f.val())) { say(M(key), 'error'); $f.trigger('focus'); return false; }
    return true;
  };
  var post = function (url, data, $btn, done) {
    $.ajax({ url: url, type: 'post', dataType: 'json', data: data,
      beforeSend: function () { busy($btn, true); },
      success: function (r) { done(r || {}); },
      error: function () { say(M('ui.connection_failed_try_again', 'Connection failed. Try again.'), 'error'); },
      complete: function () { busy($btn, false); }
    });
  };
  var emailOk = function (v) { return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v); };
  var phoneOk = function (v) { return /^\+?[0-9][0-9\s-]{5,17}$/.test(v); };
  var countdown = function (btn) {
    var left = 60, el = btn, label = el.getAttribute('data-label') || (el.value || el.textContent);
    el.setAttribute('data-label', label);
    var tick = function () {
      if (left <= 0) { el.disabled = false; if (el.tagName === 'INPUT') { el.value = label; } else { el.textContent = label; } return; }
      el.disabled = true;
      var t = fmt('ui.resend_verification_countdown', { seconds: left });
      if (el.tagName === 'INPUT') { el.value = t; } else { el.textContent = t; }
      left -= 1;
      window.setTimeout(tick, 1000);
    };
    tick();
  };
  var checkTarget = function (form) {
    var ac = $(form).find('input[name="ac"]').val();
    var to = $.trim($(form).find('input[name="to"]').val() || '');
    if (ac === 'email' && !emailOk(to)) { say(M('ui.email_format_is_incorrect'), 'error'); return false; }
    if (ac === 'phone' && !phoneOk(to)) { say(M('ui.incorrect_mobile_phone_number_format'), 'error'); return false; }
    if (ac !== 'email' && ac !== 'phone') { say(M('ui.parameter_error'), 'error'); return false; }
    return true;
  };

  var handlers = {
    login: function (form, $btn) {
      if (!need('#user_name', 'please_enter_the_user', form) || !need('#user_pwd', 'ui.please_enter_the_password_ab9294', form) || !need('#verify', 'ui.please_enter_the_verification_code_0d14a4', form)) { return; }
      post(form.getAttribute('data-url'), $(form).serialize(), $btn, function (r) {
        if (Number(r.code) === 1) { window.location.href = form.getAttribute('data-next'); return; }
        say(r.msg, 'error'); refreshCaptcha(form);
      });
    },
    reg: function (form, $btn) {
      if (!need('#user_name', 'please_enter_the_user', form) || !need('#user_pwd', 'ui.please_enter_the_password_ab9294', form)) { return; }
      if ($(form).find('#user_pwd2').length && $(form).find('#user_pwd').val() !== $(form).find('#user_pwd2').val()) { say(M('ui.the_second_password_is_inconsistent'), 'error'); $(form).find('#user_pwd2').trigger('focus'); return; }
      if (!need('#verify', 'ui.please_enter_the_verification_code_0d14a4', form)) { return; }
      post(form.getAttribute('data-url'), $(form).serialize(), $btn, function (r) {
        say(r.msg, Number(r.code) === 1 ? 'success' : 'error');
        if (Number(r.code) === 1) { window.location.href = form.getAttribute('data-next'); return; }
        refreshCaptcha(form);
      });
    },
    findpass: function (form, $btn) {
      if (!need('#user_name', 'please_enter_the_user', form) || !need('#user_question', 'nn.enter_question', form) || !need('#user_answer', 'nn.enter_answer', form) || !need('#user_pwd', 'ui.please_enter_the_new_password_ec0b3e', form)) { return; }
      if ($(form).find('#user_pwd').val() !== $(form).find('#user_pwd2').val()) { say(M('ui.the_second_password_is_inconsistent'), 'error'); return; }
      if (!need('#verify', 'ui.please_enter_the_verification_code_0d14a4', form)) { return; }
      post(form.getAttribute('data-url'), $(form).serialize(), $btn, function (r) {
        say(r.msg, Number(r.code) === 1 ? 'success' : 'error');
        if (Number(r.code) === 1) { window.location.href = form.getAttribute('data-next'); return; }
        refreshCaptcha(form);
      });
    },
    sendcode: function (form, $btn) {
      if (form.getAttribute('data-check') === 'target' && !checkTarget(form)) { return; }
      if (!need('#to', 'ui.enter_email_or_phone', form)) { return; }
      var btn = $btn[0];
      post(form.getAttribute('data-send-url'), $(form).serialize(), $btn, function (r) {
        say(r.msg, Number(r.code) === 1 ? 'success' : 'error');
        if (Number(r.code) === 1 && form.getAttribute('data-check') === 'target') { window.setTimeout(function () { countdown(btn); }, 0); }
        refreshCaptcha(form);
      });
    },
    reset: function (form, $btn) {
      var src = document.getElementById(form.getAttribute('data-source') || '') || form;
      if (!need('#to', 'ui.enter_email_or_phone', src) || !need('#code', 'ui.please_enter_the_verification_code_0d14a4', form) || !need('#user_pwd', 'ui.please_enter_the_new_password_ec0b3e', form) || !need('#user_pwd2', 'ui.please_enter_the_confirmation_password', form)) { return; }
      if ($(form).find('#user_pwd').val() !== $(form).find('#user_pwd2').val()) { say(M('ui.the_second_password_is_inconsistent'), 'error'); return; }
      var data = { ac: $(src).find('input[name="ac"]').val(), to: $(src).find('#to').val(), code: $(form).find('#code').val(), user_pwd: $(form).find('#user_pwd').val(), user_pwd2: $(form).find('#user_pwd2').val() };
      post(form.getAttribute('data-url'), data, $btn, function (r) {
        say(r.msg, Number(r.code) === 1 ? 'success' : 'error');
        if (Number(r.code) === 1 && form.getAttribute('data-next')) { window.location.href = form.getAttribute('data-next'); }
      });
    },
    info: function (form, $btn) {
      post(form.getAttribute('data-url'), $(form).serialize(), $btn, function (r) {
        say(r.msg, Number(r.code) === 1 ? 'success' : 'error');
        if (Number(r.code) === 1) { window.location.reload(); }
      });
    },
    bind: function (form, $btn) {
      if (!need('#to', 'ui.enter_email_or_phone', form) || !need('#code', 'ui.please_enter_the_verification_code_0d14a4', form)) { return; }
      post(form.getAttribute('data-url'), $(form).serialize(), $btn, function (r) {
        say(r.msg, Number(r.code) === 1 ? 'success' : 'error');
        if (Number(r.code) === 1 && form.getAttribute('data-next')) { window.location.href = form.getAttribute('data-next'); }
      });
    }
  };

  $(document).on('click', '[data-nn-submit]', function (e) {
    e.preventDefault();
    var $btn = $(this);
    var form = $btn.closest('form')[0];
    var mode = $btn.attr('data-nn-submit');
    if (form && handlers[mode]) { handlers[mode](form, $btn); }
  });
  $(document).on('submit', 'form[data-nn-form]', function (e) {
    e.preventDefault();
    var $btn = $(this).find('[data-nn-submit]').not('[data-nn-submit="sendcode"]').first();
    if ($btn.length) { $btn.trigger('click'); }
  });
  $(document).on('focusin pointerdown', 'form', wakeCaptcha);
  $(document).on('click', '#verify_img, .nn-captcha', function () { refreshCaptcha($(this).closest('form')); });
  $(document).on('keydown', '#verify_img, .nn-captcha', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); refreshCaptcha($(this).closest('form')); } });

  /* records (favourites, plays, downloads, points log, withdrawals) */
  $(document).on('click', '[data-nn-records] [data-act]', function () {
    var $form = $(this).closest('[data-nn-records]');
    var act = $(this).attr('data-act');
    var $boxes = $form.find('input[type="checkbox"][name="ids[]"]');
    if (act === 'all') { $boxes.prop('checked', true); return; }
    if (act === 'invert') { $boxes.each(function () { this.checked = !this.checked; }); return; }
    var all = act === 'clear' ? 1 : 0;
    var ids = $boxes.filter(':checked').map(function () { return this.value; }).get().join(',');
    if (!all && !ids) { say(M('ui.please_select_at_least_one_data'), 'error'); return; }
    ask(M(all ? 'ui.confirm_clear_records' : 'ui.confirm_delete_selected_records')).then(function (yes) {
      if (!yes) { return; }
      var data = { ids: ids, all: all };
      if ($form.attr('data-type')) { data.type = $form.attr('data-type'); }
      $.post($form.attr('data-url'), data, function (r) {
        if (r && Number(r.code) === 1) { say(M('ui.records_deleted'), 'success'); window.location.reload(); }
        else { say(fmt('ui.delete_failed_reason', { reason: r && r.msg ? r.msg : '' }), 'error'); }
      }, 'json');
    });
  });

  /* unbind e-mail / phone */
  $(document).on('click', '[data-nn-unbind]', function () {
    var ac = $(this).attr('data-nn-unbind');
    var url = $(this).attr('data-url');
    if (ac !== 'email' && ac !== 'phone') { say(M('ui.parameter_error'), 'error'); return; }
    ask(M('ui.confirm_the_lifting_binding_this_operation_cannot_be_restored')).then(function (yes) {
      if (!yes) { return; }
      $.ajax({ url: url, type: 'post', dataType: 'json', data: { ac: ac }, success: function (r) { say(r.msg); if (Number(r.code) === 1) { window.location.reload(); } } });
    });
  });

  /* membership upgrade */
  $(document).on('click', '[data-nn-grade]', function () {
    var $b = $(this);
    ask(fmt('ui.confirm_upgrade_membership', { name: $b.attr('data-name'), points: $b.attr('data-points') })).then(function (yes) {
      if (!yes) { return; }
      post($b.attr('data-url'), { group_id: $b.attr('data-id'), long: $b.attr('data-long') }, $b, function (r) { say(r.msg); if (Number(r.code) === 1) { window.location.reload(); } });
    });
  });

  /* recharge card */
  $(document).on('click', '[data-nn-card]', function () {
    var $b = $(this), $f = $b.closest('form');
    var no = $.trim($f.find('input[name="card_no"]').val() || ''), pwd = $.trim($f.find('input[name="card_pwd"]').val() || '');
    if (!no || !pwd) { say(M('ui.please_enter_the_recharge_card_number_and_password'), 'error'); return; }
    ask(M('ui.are_you_sure_to_use_the_recharge_card_to_recharge')).then(function (yes) {
      if (!yes) { return; }
      post($b.attr('data-url'), { card_no: no, card_pwd: pwd, flag: 'card' }, $b, function (r) { say(r.msg); if (Number(r.code) === 1) { window.location.reload(); } });
    });
  });

  /* withdrawal request */
  $(document).on('click', '[data-nn-cash]', function () {
    var $b = $(this), $f = $b.closest('form');
    var keys = [['cash_bank_name', 'ui.please_enter_the_bank_name'], ['cash_bank_no', 'ui.please_enter_the_bank_account'], ['cash_payee_name', 'ui.please_enter_the_payee'], ['cash_money', 'ui.please_enter_the_withdrawal_amount']];
    for (var i = 0; i < keys.length; i++) { if (!need('input[name="' + keys[i][0] + '"]', keys[i][1], $f)) { return; } }
    post($b.attr('data-url'), $f.serialize(), $b, function (r) { say(r.msg); if (Number(r.code) === 1) { window.location.reload(); } });
  });

  /* avatar upload */
  $(document).on('submit', 'form.nn-avatar-form', function (e) {
    e.preventDefault();
    var form = this;
    var input = form.querySelector('input[type="file"]');
    var button = form.querySelector('button[type="submit"]');
    if (!input || !input.files.length) { say(M('select_avatar_file'), 'error'); return; }
    if (!/^image\/(jpeg|png|gif|webp)$/.test(input.files[0].type)) { say(M('avatar_upload_failed'), 'error'); return; }
    var endpoint;
    try { endpoint = new URL(form.action, window.location.href); } catch (err) { return; }
    if (endpoint.origin !== window.location.origin || !window.fetch) { return; }
    if (button) { button.disabled = true; }
    window.fetch(endpoint.href, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { if (!r.ok) { throw new Error('upload'); } return r.json(); })
      .then(function (r) {
        if (r && typeof r.__token__ === 'string') { $('input[name="__token__"]').val(r.__token__); }
        if (!r || Number(r.code) !== 1) { say(r && r.msg ? r.msg : M('ui.connection_failed_try_again'), 'error'); return; }
        say(M('avatar_updated'), 'success');
        window.location.reload();
      })
      .catch(function () { say(M('ui.connection_failed_try_again'), 'error'); })
      .then(function () { if (button) { button.disabled = false; } });
  });
  $(document).on('change', 'form.nn-avatar-form input[type="file"]', function () {
    var name = this.files && this.files[0] ? this.files[0].name : '';
    $(this).closest('form').find('[data-nn-file-name]').text(name);
  });
})(window, document, window.jQuery);
