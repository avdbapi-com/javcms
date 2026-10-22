/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
/* JavCMS admin UI kit script (static/js/admin_ux.js). Brief ADMIN-IA-BRIEF.md section 6.3.
   Loaded LAST from application/admin/view/public/head.html, after jQuery, layui and admin_studio.js.
   Public API (every other admin script calls only this):
     AxUX.confirm({title, message, messageOne, confirmLabel, cancelLabel, danger, typed, count}) -> Promise<boolean>
     AxUX.toast(text, 'ok' | 'warn' | 'error' | 'info')
     AxUX.isDirty() / AxUX.markClean() / AxUX.markDirty()     (forms or containers with data-ax-dirty)
     AxUX.menu(el)                                             (.ax-menu wrapper or its button)
     AxUX.t(key, vars) / AxUX.format(text, vars) / AxUX.icon(name, extraClass) / AxUX.selectedCount(scope)
     AxUX.closeMenus() / AxUX.closeTopLayer()
   Declarative: .ax-confirm-link[data-ax-confirm] (+ data-ax-confirm-title, data-ax-typed, data-ax-confirm-ok,
   data-ax-danger="0"); "{n}" in a message = the number of ticked rows. Strings come from window.AX_LANG (head.html).
   No dependency except layui's global "layer" (optional, only for closing layers). ES5 + Promise. */
(function (window, document) {
  'use strict';
  if (window.AxUX) return;

  var LANG = window.AX_LANG || {};
  var SPRITE = window.AX_ICONS || '';
  var FALLBACK = {
    confirm_title: 'Are you sure?', confirm_default: 'Do this for {n} selected items?', confirm_default_one: 'Do this for the selected item?', confirm_ok: 'Confirm',
    cancel: 'Cancel', type_to_confirm: 'Type %s to confirm', cannot_undo: 'This cannot be undone.',
    select_first: 'Select at least one item first.', selected: '{n} selected', bulk: 'Bulk actions', more: 'More actions',
    back: 'Back', save: 'Save', saved_at: 'Saved %s', close: 'Close', danger_zone: 'Danger zone',
    empty_default: 'Nothing here yet.', loading: 'Loading...', request_failed: 'The request failed. Try again.'
  };
  var inFrame = false;
  try { inFrame = window.parent && window.parent !== window; } catch (e) { inFrame = true; }

  function t(key, vars) {
    var s = Object.prototype.hasOwnProperty.call(LANG, key) && typeof LANG[key] === 'string' ? LANG[key] : (FALLBACK[key] || key);
    return format(s, vars);
  }
  function format(text, vars) {
    text = text == null ? '' : String(text);
    if (vars == null) return text;
    if (typeof vars !== 'object') vars = { s: vars };
    return text.replace(/\{([a-z_][a-z0-9_]*)\}/gi, function (m, k) {
      return Object.prototype.hasOwnProperty.call(vars, k) ? String(vars[k]) : m;
    }).replace(/%s/g, function () { return vars.s != null ? String(vars.s) : '%s'; });
  }
  function icon(name, extra) {
    var ns = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('class', 'ax-ic' + (extra ? ' ' + extra : ''));
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    var use = document.createElementNS(ns, 'use');
    use.setAttribute('href', SPRITE + '#ai-' + String(name).replace(/^ai-/, ''));
    svg.appendChild(use);
    return svg;
  }
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }
  function closest(node, sel) {
    while (node && node.nodeType === 1) {
      if (matches(node, sel)) return node;
      node = node.parentNode;
    }
    return null;
  }
  function matches(node, sel) {
    var f = node.matches || node.msMatchesSelector || node.webkitMatchesSelector;
    return f ? f.call(node, sel) : false;
  }
  function onReady(fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  }
  function getLayer() {
    try { return window.layer || (window.layui && window.layui.layer) || null; } catch (e) { return null; }
  }
  function selectedCount(scope) {
    var root = scope || document.getElementById('pageListForm') || document;
    return root.querySelectorAll('input.checkbox-ids:checked, input[name="ids[]"]:checked').length;
  }

  /* ---------- Toast (component 17) ---------- */
  var toastBox = null;
  function toast(text, kind) {
    kind = /^(ok|warn|error|info)$/.test(kind || '') ? kind : 'ok';
    if (!document.body) { onReady(function () { toast(text, kind); }); return; }
    if (!toastBox || !toastBox.isConnected) {
      toastBox = el('div', 'ax-toasts');
      document.body.appendChild(toastBox);
    }
    var item = el('div', 'ax-toast ax-toast--' + kind);
    item.setAttribute('role', 'status');
    item.setAttribute('aria-live', 'polite');
    item.appendChild(icon(kind === 'ok' ? 'circle-check' : kind === 'error' ? 'circle-alert' : kind === 'warn' ? 'triangle-alert' : 'info'));
    item.appendChild(el('span', '', text));
    toastBox.appendChild(item);
    window.requestAnimationFrame(function () { item.classList.add('is-in'); });
    setTimeout(function () {
      item.classList.remove('is-in');
      setTimeout(function () { if (item.parentNode) item.parentNode.removeChild(item); }, 200);
    }, 2500);
    return item;
  }

  /* ---------- Confirm dialog (component 14) ---------- */
  var openDialog = null;
  var dialogSeq = 0;
  function confirmDialog(opts) {
    opts = opts || {};
    if (openDialog) openDialog.finish(false);
    return new Promise(function (resolve) {
      var count = opts.count != null ? opts.count : selectedCount();
      var vars = { n: count, s: opts.typed || '' };
      var danger = opts.danger !== false;
      var typed = opts.typed ? String(opts.typed) : '';
      var id = 'ax-dialog-' + (++dialogSeq);
      var prevFocus = document.activeElement;

      var backdrop = el('div', 'ax-dialog-backdrop');
      var box = el('div', 'ax-dialog' + (danger ? ' ax-dialog--danger' : ''));
      box.setAttribute('role', 'alertdialog');
      box.setAttribute('aria-modal', 'true');
      box.setAttribute('aria-labelledby', id + '-t');
      box.setAttribute('aria-describedby', id + '-m');
      var head = el('div', 'ax-dialog__head');
      var ic = el('span', 'ax-dialog__icon');
      ic.appendChild(icon(danger ? 'triangle-alert' : 'circle-help'));
      head.appendChild(ic);
      // R2-15: a menu label such as "Delete all comments…" keeps its "…" in the menu, never in the dialog title.
      var title = el('h2', 'ax-dialog__title', String(format(opts.title || t('confirm_title'), vars)).replace(/\s*(…|\.\.\.)\s*$/, ''));
      title.id = id + '-t';
      head.appendChild(title);
      box.appendChild(head);
      // r5: one row = a singular sentence (opts.messageOne, else the generic "the selected item" text), never "1 ... items".
      var message = opts.message || t('confirm_default');
      if (count === 1 && opts.messageOne) message = opts.messageOne;
      else if (count === 1 && message === t('confirm_default')) message = t('confirm_default_one');
      var msg = el('p', 'ax-dialog__msg', format(message, vars));
      msg.id = id + '-m';
      box.appendChild(msg);

      var input = null;
      if (typed) {
        var wrap = el('div', 'ax-dialog__typed');
        var label = el('label');
        label.htmlFor = id + '-i';
        var parts = t('type_to_confirm').split('%s');
        label.appendChild(document.createTextNode(parts[0] || ''));
        label.appendChild(el('strong', '', typed));
        label.appendChild(document.createTextNode(parts.slice(1).join('%s')));
        input = el('input');
        input.type = 'text';
        input.id = id + '-i';
        input.autocomplete = 'off';
        input.spellcheck = false;
        input.setAttribute('autocapitalize', 'off');
        wrap.appendChild(label);
        wrap.appendChild(input);
        box.appendChild(wrap);
      }
      var actions = el('div', 'ax-dialog__actions');
      var cancel = el('button', 'ax-btn', opts.cancelLabel || t('cancel'));
      cancel.type = 'button';
      var ok = el('button', 'ax-btn ' + (danger ? 'ax-btn--danger-solid' : 'ax-btn--primary'), format(opts.confirmLabel || t('confirm_ok'), vars));
      ok.type = 'button';
      actions.appendChild(cancel);
      actions.appendChild(ok);
      box.appendChild(actions);
      backdrop.appendChild(box);

      function sync() {
        var good = !input || input.value === typed;
        ok.disabled = !good;
        ok.setAttribute('aria-disabled', good ? 'false' : 'true');
      }
      var done = false;
      function finish(result) {
        if (done) return;
        done = true;
        openDialog = null;
        document.removeEventListener('keydown', onKey, true);
        document.documentElement.classList.remove('ax-dialog-open');
        if (backdrop.parentNode) backdrop.parentNode.removeChild(backdrop);
        // r5: a dialog opened from a menu item gives focus back to that menu's button (the item is hidden by then).
        var back = prevFocus;
        if (back && back.closest && back.isConnected && !back.offsetParent) {
          var menuWrap = back.closest('.ax-menu');
          var menuBtn = menuWrap && menuWrap.querySelector('button[aria-haspopup]');
          if (menuBtn && menuBtn.offsetParent) back = menuBtn;
        }
        if (back && back.focus && back.isConnected) { try { back.focus({ preventScroll: true }); } catch (e) { /* ignore */ } }
        resolve(!!result);
      }
      function focusables() {
        return Array.prototype.filter.call(box.querySelectorAll('button, input'), function (n) { return !n.disabled; });
      }
      function onKey(e) {
        if (!openDialog || openDialog.box !== box) return;
        if (e.key === 'Escape' || e.key === 'Esc') {
          e.preventDefault(); e.stopPropagation();
          if (e.stopImmediatePropagation) e.stopImmediatePropagation();
          finish(false);
        } else if (e.key === 'Tab') {
          var list = focusables();
          if (!list.length) return;
          var first = list[0], last = list[list.length - 1];
          if (!box.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
          else if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
          else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        } else if (e.key === 'Enter' && input && document.activeElement === input) {
          e.preventDefault();
          if (!ok.disabled) finish(true);
        }
      }
      cancel.addEventListener('click', function () { finish(false); });
      ok.addEventListener('click', function () { if (!ok.disabled) finish(true); });
      backdrop.addEventListener('mousedown', function (e) { if (e.target === backdrop) finish(false); });
      if (input) input.addEventListener('input', sync);
      sync();
      document.addEventListener('keydown', onKey, true);
      document.documentElement.classList.add('ax-dialog-open');
      document.body.appendChild(backdrop);
      openDialog = { box: box, finish: finish };
      window.requestAnimationFrame(function () { backdrop.classList.add('is-in'); });
      cancel.focus();
    });
  }

  /* ---------- Dirty forms ---------- */
  var dirty = false;
  var submitting = false;
  function markDirtyFrom(target) {
    if (target && target.nodeType === 1 && closest(target, '[data-ax-dirty]')) dirty = true;
  }
  document.addEventListener('input', function (e) { markDirtyFrom(e.target); }, true);
  document.addEventListener('change', function (e) { markDirtyFrom(e.target); }, true);
  document.addEventListener('click', function (e) {
    var skin = e.target && e.target.nodeType === 1 ? closest(e.target, '.layui-form-checkbox, .layui-form-radio, .layui-form-switch, .layui-form-select dd') : null;
    if (skin) markDirtyFrom(skin);
  }, true);
  // R2-13: "Undo changes" (a reset button) puts the only changed form back as it was loaded: nothing is left to lose.
  document.addEventListener('reset', function (e) {
    var f = e.target;
    if (f && f.nodeType === 1 && closest(f, '[data-ax-dirty]') && document.querySelectorAll('[data-ax-dirty]').length === 1) dirty = false;
  }, true);
  window.addEventListener('submit', function (e) { if (!e.defaultPrevented) submitting = true; });
  window.addEventListener('beforeunload', function (e) {
    if (dirty && !submitting) { e.preventDefault(); e.returnValue = ''; return ''; }
  });

  /* ---------- Menu (component 4) ---------- */
  var menuSeq = 0;
  function menuParts(root) {
    var btn = root.querySelector('[aria-haspopup]');
    var list = root.querySelector('.ax-menu__list, [role="menu"]');
    return { btn: btn, list: list };
  }
  function menuItems(list) {
    return Array.prototype.filter.call(list.querySelectorAll('[role="menuitem"], .ax-menu__item'), function (n) {
      return !n.disabled && n.getAttribute('aria-disabled') !== 'true' && n.offsetParent !== null;
    });
  }
  function openMenu(root, focusFirst) {
    var p = menuParts(root);
    if (!p.btn || !p.list) return;
    closeMenus(root);
    p.list.hidden = false;
    root.classList.add('is-open');
    p.btn.setAttribute('aria-expanded', 'true');
    root.classList.remove('ax-menu--up', 'ax-menu--end');
    if (closest(root, '.ax-table-wrap')) {
      // review-visual-r4: a menu inside a table scroll box (overflow-x:auto) is clipped by it on short tables and on the
      // last rows. Float it (position:fixed) next to its button, so it is never clipped; flip up when there is no room below.
      root.classList.add('ax-menu--float');
      placeFloat(root);
    } else {
      var r = p.list.getBoundingClientRect();
      if (r.right > window.innerWidth - 8) root.classList.add('ax-menu--end');
      if (r.bottom > window.innerHeight - 8 && p.btn.getBoundingClientRect().top > r.height + 12) root.classList.add('ax-menu--up');
    }
    if (focusFirst) { var items = menuItems(p.list); if (items[0]) items[0].focus(); }
  }
  function placeFloat(root) {
    var p = menuParts(root);
    if (!p.btn || !p.list || p.list.hidden) return;
    p.list.style.top = '0px'; p.list.style.left = '0px';
    var b = p.btn.getBoundingClientRect(), lr = p.list.getBoundingClientRect();
    var vw = document.documentElement.clientWidth || window.innerWidth, vh = window.innerHeight;
    var left = Math.max(8, Math.min(b.right - lr.width, vw - lr.width - 8));
    var top = b.bottom + 4;
    if (top + lr.height > vh - 8 && b.top - 4 - lr.height >= 8) top = b.top - 4 - lr.height;
    p.list.style.left = Math.round(left) + 'px';
    p.list.style.top = Math.round(Math.max(8, Math.min(top, vh - lr.height - 8))) + 'px';
  }
  function closeMenu(root, restore) {
    var p = menuParts(root);
    if (!p.list || p.list.hidden) return false;
    p.list.hidden = true;
    root.classList.remove('is-open', 'ax-menu--float');
    p.list.style.top = ''; p.list.style.left = '';
    if (p.btn) {
      p.btn.setAttribute('aria-expanded', 'false');
      if (restore) p.btn.focus();
    }
    return true;
  }
  function closeMenus(except) {
    var closed = false;
    Array.prototype.forEach.call(document.querySelectorAll('.ax-menu.is-open'), function (m) {
      if (m !== except && closeMenu(m, false)) closed = true;
    });
    return closed;
  }
  function placeFloats() {
    Array.prototype.forEach.call(document.querySelectorAll('.ax-menu--float.is-open'), placeFloat);
  }
  window.addEventListener('scroll', placeFloats, true);
  window.addEventListener('resize', placeFloats);
  function bindMenu(target) {
    if (!target) return null;
    var root = matches(target, '.ax-menu') ? target : closest(target, '.ax-menu');
    if (!root || root.getAttribute('data-ax-menu-bound') === '1') return root;
    var p = menuParts(root);
    if (!p.btn || !p.list) return root;
    root.setAttribute('data-ax-menu-bound', '1');
    if (!p.list.id) p.list.id = 'ax-menu-' + (++menuSeq);
    p.list.setAttribute('role', 'menu');
    p.btn.setAttribute('aria-haspopup', 'menu');
    p.btn.setAttribute('aria-controls', p.list.id);
    p.btn.setAttribute('aria-expanded', 'false');
    p.list.hidden = true;
    Array.prototype.forEach.call(p.list.querySelectorAll('.ax-menu__item'), function (n) {
      if (!n.hasAttribute('role')) n.setAttribute('role', 'menuitem');
      n.setAttribute('tabindex', '-1');
    });
    p.btn.addEventListener('click', function (e) {
      e.preventDefault();
      if (root.classList.contains('is-open')) closeMenu(root, false); else openMenu(root, false);
    });
    p.btn.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); openMenu(root, true); }
    });
    p.list.addEventListener('keydown', function (e) {
      var items = menuItems(p.list);
      var i = items.indexOf(document.activeElement);
      if (e.key === 'ArrowDown') { e.preventDefault(); (items[i + 1] || items[0]).focus(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); (items[i - 1] || items[items.length - 1]).focus(); }
      else if (e.key === 'Home') { e.preventDefault(); if (items[0]) items[0].focus(); }
      else if (e.key === 'End') { e.preventDefault(); if (items.length) items[items.length - 1].focus(); }
      else if (e.key === 'Tab') { closeMenu(root, false); }
      else if ((e.key === 'Enter' || e.key === ' ') && i >= 0 && items[i].tagName !== 'BUTTON' && items[i].tagName !== 'A') { e.preventDefault(); items[i].click(); }
    });
    p.list.addEventListener('click', function (e) {
      var item = closest(e.target, '[role="menuitem"], .ax-menu__item');
      if (item && p.list.contains(item)) setTimeout(function () { closeMenu(root, false); }, 0);
    });
    return root;
  }
  function bindMenus(scope) {
    Array.prototype.forEach.call((scope || document).querySelectorAll('.ax-menu'), bindMenu);
  }
  // outside click closes (capture: runs before handlers that stop propagation)
  document.addEventListener('pointerdown', function (e) {
    var inside = e.target && e.target.nodeType === 1 ? closest(e.target, '.ax-menu') : null;
    closeMenus(inside);
  }, true);
  window.addEventListener('blur', function () { closeMenus(null); });

  /* ---------- Layers (layui): Esc closes the top-most one (F9) ---------- */
  function topLayer() {
    var best = null, bestZ = -1;
    Array.prototype.forEach.call(document.querySelectorAll('.layui-layer[times]'), function (n) {
      if (n.classList.contains('layui-layer-loading') || n.classList.contains('layui-layer-msg') || n.classList.contains('layui-layer-hui')) return;
      var cs = window.getComputedStyle(n);
      if (cs.display === 'none' || cs.visibility === 'hidden') return;
      var z = parseInt(cs.zIndex, 10) || 0;
      if (z >= bestZ) { bestZ = z; best = n; }
    });
    return best;
  }
  function frameIsDirty(layerNode) {
    var frame = layerNode.querySelector('iframe');
    if (!frame) return false;
    try { return !!(frame.contentWindow && frame.contentWindow.AxUX && frame.contentWindow.AxUX.isDirty()); } catch (e) { return false; }
  }
  function closeTopLayer() {
    var lay = getLayer();
    var node = topLayer();
    if (!lay || !node) return false;
    var open = node.querySelector('.layui-form-selected');
    if (open) { open.classList.remove('layui-form-selected'); return true; }
    if (frameIsDirty(node)) return true; // never drop typed work with Esc
    lay.close(parseInt(node.getAttribute('times'), 10));
    return true;
  }
  // This page is itself shown inside a layui iframe layer of the parent page: Esc closes that layer.
  function closeOwnFrameLayer() {
    if (!inFrame || dirty) return false;
    try {
      var fe = window.frameElement;
      var pl = window.parent.layer;
      if (!fe || !pl) return false;
      var host = fe.parentNode;
      while (host && host.nodeType === 1 && !(host.classList && host.classList.contains('layui-layer'))) host = host.parentNode;
      if (!host || host.nodeType !== 1 || !host.getAttribute('times')) return false;
      pl.close(parseInt(host.getAttribute('times'), 10));
      return true;
    } catch (e) { return false; }
  }

  /* ---------- Messages to / from the shell (F2 sender, 4.4) ---------- */
  function tellParent(type) {
    if (!inFrame) return;
    try { window.parent.postMessage({ ax: type }, window.location.origin); } catch (e) { /* ignore */ }
  }
  document.addEventListener('pointerdown', function () { tellParent('close-menus'); }, true);
  window.addEventListener('message', function (e) {
    if (e.origin !== window.location.origin || !e.data || typeof e.data !== 'object') return;
    if (e.data.ax === 'close-menus' || e.data.ax === 'escape') {
      closeMenus(null);
      tellParent(e.data.ax); // relay up: list page -> shell
    }
  });

  /* ---------- Esc ---------- */
  document.addEventListener('keydown', function (e) {
    if ((e.key !== 'Escape' && e.key !== 'Esc') || e.defaultPrevented) return;
    if (openDialog) return; // the dialog handles its own Esc
    var handled = closeTopLayer();
    if (!handled) {
      var active = document.querySelector('.ax-menu.is-open');
      if (active) { closeMenu(active, active.contains(document.activeElement)); handled = true; }
    }
    if (!handled) handled = closeOwnFrameLayer();
    tellParent('escape');
  });

  /* ---------- Declarative confirm: a.ax-confirm-link[data-ax-confirm] / button.ax-confirm-link ---------- */
  document.addEventListener('click', function (e) {
    var node = e.target && e.target.nodeType === 1 ? closest(e.target, '.ax-confirm-link[data-ax-confirm]') : null;
    if (!node) return;
    if (node._axConfirmed) { node._axConfirmed = false; return; }
    e.preventDefault();
    e.stopPropagation();
    if (e.stopImmediatePropagation) e.stopImmediatePropagation();
    var message = node.getAttribute('data-ax-confirm') || '';
    var count = selectedCount();
    if (/\{n\}/.test(message) && count === 0 && node.getAttribute('data-ax-count') == null) { toast(t('select_first'), 'warn'); return; }
    if (node.getAttribute('data-ax-count') != null) count = parseInt(node.getAttribute('data-ax-count'), 10) || 0;
    confirmDialog({
      title: node.getAttribute('data-ax-confirm-title') || undefined,
      message: message || undefined,
      confirmLabel: node.getAttribute('data-ax-confirm-ok') || undefined,
      typed: node.getAttribute('data-ax-typed') || '',
      danger: node.getAttribute('data-ax-danger') !== '0',
      count: count
    }).then(function (yes) {
      if (!yes) return;
      node._axConfirmed = true;
      if (node.tagName === 'A' && node.getAttribute('href') && !/^javascript:/i.test(node.getAttribute('href')) && !node.hasAttribute('data-href')) {
        node._axConfirmed = false;
        if (node.target === '_blank') window.open(node.href, '_blank', 'noopener'); else window.location.href = node.href;
        return;
      }
      node.click();
    });
  }, true);

  /* ---------- V6: keyboard for layui radio / checkbox / switch skins ---------- */
  var SKIN = '.layui-form-checkbox, .layui-form-switch, .layui-form-radio';
  function skinInput(skin) {
    var n = skin.previousElementSibling;
    return n && n.tagName === 'INPUT' ? n : null;
  }
  function syncSkin(skin) {
    var inp = skinInput(skin);
    if (!inp) return;
    var isRadio = inp.type === 'radio';
    var isSwitch = skin.classList.contains('layui-form-switch') || inp.getAttribute('lay-skin') === 'switch';
    skin.setAttribute('role', isRadio ? 'radio' : (isSwitch ? 'switch' : 'checkbox'));
    skin.setAttribute('aria-checked', inp.checked ? 'true' : 'false');
    if (inp.disabled) { skin.setAttribute('aria-disabled', 'true'); skin.removeAttribute('tabindex'); return; }
    skin.removeAttribute('aria-disabled');
    skin.setAttribute('tabindex', '0');
    if (!skin.hasAttribute('aria-label')) {
      var label = (skin.textContent || '').replace(/\s+/g, ' ').trim() || inp.getAttribute('title') || inp.getAttribute('lay-text') || '';
      if (!label) {
        var row = closest(skin, '.layui-form-item');
        var lab = row ? row.querySelector('.layui-form-label') : null;
        label = lab ? lab.textContent.replace(/\s+/g, ' ').trim() : '';
      }
      if (label) skin.setAttribute('aria-label', label.split('|')[0]);
    }
  }
  function scanSkins(root) {
    var scope = root && root.querySelectorAll ? root : document;
    if (scope.nodeType === 1 && matches(scope, SKIN)) syncSkin(scope);
    Array.prototype.forEach.call(scope.querySelectorAll(SKIN), syncSkin);
  }
  document.addEventListener('keydown', function (e) {
    if (e.key !== ' ' && e.key !== 'Spacebar' && e.key !== 'Enter') return;
    var skin = e.target && e.target.nodeType === 1 && matches(e.target, SKIN) ? e.target : null;
    if (!skin) return;
    e.preventDefault();
    var inp = skinInput(skin);
    skin.click();
    setTimeout(function () {
      var now = inp && inp.nextElementSibling;
      if (!skin.isConnected && now && matches(now, SKIN)) { syncSkin(now); now.focus(); }
      else syncSkin(skin);
    }, 0);
  });
  document.addEventListener('click', function (e) {
    var skin = e.target && e.target.nodeType === 1 ? closest(e.target, SKIN) : null;
    if (skin) setTimeout(function () { scanSkins(closest(skin, 'form') || document); }, 0);
  });

  /* ---------- N10: system/config?section=code lands on the ads & analytics field;
     beginner r3 P3-6: ?section=timezone lands on the time zone field (tab "Admin panel") ---------- */
  // r5 repair (beginner r5 P2-2 / P2-3): ?section=analytics (Google Analytics ID) and ?section=home (Home page title).
  var SECTION_FIELDS = { code: 'textarea[name="site[site_tj]"]', timezone: '#app-timezone', analytics: '#site-ga4', home: '#site-home-title' };
  function sectionCode() {
    var m = /[?&]section=(code|timezone|analytics|home)(&|$)/.exec(window.location.search);
    if (!m) return;
    var field = document.querySelector(SECTION_FIELDS[m[1]]);
    if (!field) return;
    var item = closest(field, '.layui-tab-item');
    var tab = item ? closest(item, '.layui-tab') : null;
    if (item && tab) {
      var items = Array.prototype.filter.call(item.parentNode.children, function (n) { return n.classList.contains('layui-tab-item'); });
      var idx = items.indexOf(item);
      var title = tab.querySelector('.layui-tab-title');
      var li = title ? title.children[idx] : null;
      if (li && !li.classList.contains('layui-this')) li.click();
      if (!item.classList.contains('layui-show')) {
        items.forEach(function (n) { n.classList.toggle('layui-show', n === item); });
        if (title) Array.prototype.forEach.call(title.children, function (n, i) { n.classList.toggle('layui-this', i === idx); });
      }
    }
    setTimeout(function () {
      // A layui select hides the real <select>: scroll to and focus its visible input instead.
      var shown = field;
      if (field.tagName === 'SELECT' && field.nextElementSibling && field.nextElementSibling.classList.contains('layui-form-select')) {
        shown = field.nextElementSibling.querySelector('input') || field.nextElementSibling;
      }
      try { shown.scrollIntoView({ block: 'center' }); } catch (e) { shown.scrollIntoView(); }
      try { shown.focus({ preventScroll: true }); } catch (e) { shown.focus(); }
    }, 60);
  }

  /* ---------- R2-1: mark list tables that scroll sideways (the pinned row-actions column then gets its edge shadow) ---------- */
  function markScrollTables() {
    Array.prototype.forEach.call(document.querySelectorAll('.ax-table-wrap'), function (w) {
      w.classList.toggle('is-scrollable', w.scrollWidth > w.clientWidth + 1);
    });
  }
  var scrollTimer = null;
  window.addEventListener('resize', function () {
    if (scrollTimer) clearTimeout(scrollTimer);
    scrollTimer = setTimeout(markScrollTables, 120);
  });

  /* ---------- R2-7: one primary button per view. An empty-state action becomes secondary when the page
     already shows another primary button (header "+ Add", Save). The partial opts out with data-ax-keep-primary. ---------- */
  function onePrimary() {
    var shown = function (n) { return n.getClientRects().length > 0; };
    var others = Array.prototype.filter.call(document.querySelectorAll('.ax-btn--primary'), function (n) {
      return !closest(n, '.ax-empty') && shown(n);
    });
    if (!others.length) return;
    Array.prototype.forEach.call(document.querySelectorAll('.ax-empty .ax-btn--primary:not([data-ax-keep-primary])'), function (n) {
      n.classList.remove('ax-btn--primary');
    });
  }

  /* ---------- start ---------- */
  var pending = null;
  function flush() {
    var roots = pending; pending = null;
    if (!roots) return;
    if (roots === document || roots.length > 40) { scanSkins(document); bindMenus(document); return; }
    roots.forEach(function (r) { if (r.isConnected) { scanSkins(r); bindMenus(r); } });
  }
  onReady(function () {
    scanSkins(document);
    bindMenus(document);
    onePrimary();
    markScrollTables();
    window.addEventListener('load', markScrollTables);
    if (window.MutationObserver) {
      new MutationObserver(function (records) {
        for (var i = 0; i < records.length; i++) {
          var added = records[i].addedNodes;
          for (var j = 0; j < added.length; j++) {
            var n = added[j];
            if (n.nodeType !== 1 || (n.classList && (n.classList.contains('ax-toasts') || n.classList.contains('ax-toast') || n.classList.contains('ax-dialog-backdrop')))) continue;
            if (pending === document) break;
            if (!pending) { pending = []; window.requestAnimationFrame(flush); }
            if (pending !== document && pending.indexOf(n) < 0) pending.push(n);
          }
        }
      }).observe(document.body, { childList: true, subtree: true });
    }
    if (document.readyState === 'complete') setTimeout(sectionCode, 120);
    else window.addEventListener('load', function () { setTimeout(sectionCode, 120); });
  });

  window.AxUX = {
    version: '1',
    t: t,
    format: format,
    icon: icon,
    toast: toast,
    confirm: confirmDialog,
    isDirty: function () { return dirty; },
    markClean: function () { dirty = false; submitting = false; },
    markDirty: function () { dirty = true; },
    menu: bindMenu,
    closeMenus: function () { return closeMenus(null); },
    closeTopLayer: closeTopLayer,
    selectedCount: selectedCount
  };
}(window, document));
