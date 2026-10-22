/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
/* Progressive layout enhancements; never submit, duplicate, disable or rename data controls. */
(function () {
  'use strict';
  function string(key, fallback, count) {
    var strings = window.adminStudioStrings || {};
    var value = typeof strings[key] === 'string' ? strings[key] : fallback;
    return count === undefined ? value : value.replace(/\{count\}|:count|%d/g, String(count));
  }
  function childrenMatching(node, selector) {
    return Array.prototype.filter.call(node.children, function (child) { return child.matches(selector); });
  }
  function element(tag, className, text) {
    var node = document.createElement(tag);
    node.className = className || '';
    if (text) node.textContent = text;
    return node;
  }
  function labelFor(control) {
    if (control.tagName === 'SELECT') return control.options[0] ? control.options[0].textContent.trim() : 'Filter';
    return control.getAttribute('aria-label') || control.placeholder || control.name.replace(/[_\[\]]+/g, ' ');
  }
  var filterSequence = 0;
  function improveFilters(page) {
    page.querySelectorAll('.my-toolbar-box form').forEach(function (form) {
      if (form.querySelector('.studio-filter-panel')) return;
      var groups = childrenMatching(form, '.layui-input-inline');
      if (groups.length < 4 || !form.querySelector('.j-search')) return;
      form.classList.add('studio-search-form');
      var details = element('details', 'studio-filter-panel');
      var summary = element('summary', '', string('more_filters', 'More filters'));
      var fields = element('div', 'studio-filter-fields');
      var basic = 0, active = 0;
      groups.forEach(function (group, index) {
        var control = group.querySelector('select[name],input[name]:not([type=hidden])');
        if (!control) return;
        if (control.tagName === 'INPUT' && /^(wd|keyword|keywords|search)$/.test(control.name)) {
          group.classList.add('studio-search-text');
          if (!control.getAttribute('aria-label')) control.setAttribute('aria-label', labelFor(control));
          return;
        }
        if (basic++ < 2) return;
        var label = element('label', '', labelFor(control));
        if (!control.id) control.id = 'studio-filter-' + (++filterSequence);
        label.htmlFor = control.id;
        group.insertBefore(label, group.firstChild);
        fields.appendChild(group);
        if (control.tagName === 'SELECT' ? control.selectedIndex > 0 : control.value !== '') active++;
      });
      if (!fields.children.length) return;
      summary.textContent = active ? string('active_filters', 'More filters ({count} active)', active) : string('more_filters', 'More filters');
      details.open = active > 0;
      details.appendChild(summary); details.appendChild(fields); form.appendChild(details);
    });
  }
  var bulkMenuSequence = 0;
  function dismissBulkMenu(menu, summary, items, closeButton) {
    function close(restoreFocus) {
      menu.open = false;
      if (restoreFocus) summary.focus();
    }
    // Capture runs before legacy jQuery action handlers that stop bubbling.
    // Keep the original event and nodes intact for confirmations and dialogs.
    document.addEventListener('click', function (event) {
      if (!menu.open) return;
      var target = event.target;
      if (closeButton.contains(target)) close(true);
      else if (!menu.contains(target)) close(false);
      else if (items.contains(target) && target.closest('a,button')) close(false);
    }, true);
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && menu.open) close(menu.contains(document.activeElement));
    }, true);
  }
  function improveActions(page) {
    page.querySelectorAll('.my-toolbar-box > .btn-primary-group').forEach(function (bar) {
      var links = childrenMatching(bar, 'a,button');
      bar.classList.add('studio-bulk-tools');
      var existingMenu = bar.querySelector('.studio-bulk-menu');
      if (existingMenu) {
        var existingSummary = existingMenu.querySelector('summary');
        var existingItems = existingMenu.querySelector(':scope > div');
        var existingClose = existingMenu.querySelector('.studio-bulk-close');
        if (existingSummary && existingItems && existingClose) {
          dismissBulkMenu(existingMenu, existingSummary, existingItems, existingClose);
          existingSummary.setAttribute('aria-expanded', String(existingMenu.open));
          existingMenu.addEventListener('toggle', function () { existingSummary.setAttribute('aria-expanded', String(existingMenu.open)); });
        }
      }
      if (!existingMenu && links.length > 5) {
        var menu = element('details', 'studio-bulk-menu');
        var summary = element('summary', '', string('bulk_actions', 'Bulk actions'));
        var items = element('div');
        links.forEach(function (link) {
          if (link.matches('.j-page-btns,.j-select') || /images\/opt|make\/make/.test(link.getAttribute('data-href') || '')) items.appendChild(link);
        });
        if (items.children.length) {
          var heading = element('div', 'studio-bulk-heading');
          var closeButton = element('button', 'studio-bulk-close', string('close', 'Close'));
          closeButton.type = 'button';
          closeButton.setAttribute('aria-label', string('close_bulk', 'Close bulk actions'));
          heading.appendChild(element('span', '', string('actions', 'Actions')));
          heading.appendChild(closeButton);
          items.insertBefore(heading, items.firstChild);
          items.id = 'studio-bulk-items-' + (++bulkMenuSequence);
          summary.setAttribute('aria-controls', items.id);
          summary.setAttribute('aria-expanded', 'false');
          menu.addEventListener('toggle', function () { summary.setAttribute('aria-expanded', String(menu.open)); });
          menu.appendChild(summary); menu.appendChild(items); bar.appendChild(menu);
          dismissBulkMenu(menu, summary, items, closeButton);
        }
      }
      var count = bar.querySelector('.studio-selection-count') || element('span', 'studio-selection-count', string('select_rows', 'Select rows for bulk actions'));
      count.setAttribute('aria-live', 'polite');
      if (!count.parentNode) bar.appendChild(count);
      function refreshCount() {
        var selected = page.querySelectorAll('#pageListForm .checkbox-ids:checked').length;
        count.textContent = selected ? string('selected', '{count} selected', selected) : string('select_rows', 'Select rows for bulk actions');
      }
      page.addEventListener('change', refreshCount);
      page.addEventListener('click', function (event) {
        if (event.target.closest('.layui-form-checkbox,.layui-form-checkbox[lay-skin],input[type=checkbox]')) setTimeout(refreshCount, 0);
      });
      refreshCount();
    });
  }
  function improveForm(form) {
    if (!form.matches('.layui-form-pane') || form.closest('.admin-vod-info,.studio-vod-editor')) return;
    form.querySelectorAll('.layui-form-item').forEach(function (row) {
      if (row.closest('.layui-form-item') !== row) return;
      var labels = childrenMatching(row, '.layui-form-label');
      var controls = childrenMatching(row, '.layui-input-inline,.layui-input-block');
      if (labels.length === 1) row.classList.add('studio-field-row');
      if (labels.length > 1 && labels.length === controls.length && controls.every(function (block) { return !block.querySelector('button,.layui-upload'); })) {
        row.classList.add('studio-field-paired');
        row.style.setProperty('--studio-field-count', labels.length);
        labels.forEach(function (label, i) { label.style.gridColumn = i + 1; label.style.gridRow = 1; });
        controls.forEach(function (block, i) { block.style.gridColumn = i + 1; block.style.gridRow = 2; });
      }
      if (!labels.length && row.querySelector('.j-ajax-submit,[lay-submit]')) row.classList.add('studio-form-actions');
    });
    var panels = Array.prototype.slice.call(form.querySelectorAll('.layui-tab-item'));
    if (!form.querySelector('.layui-tab')) panels.push(form);
    panels.forEach(function (panel) {
      var rows = childrenMatching(panel, '.layui-form-item');
      if (rows.length < 4 || childrenMatching(panel, 'table,.layui-tab').length) return;
      panel.classList.add('studio-form-panel');
      rows.forEach(function (row) {
        if (row.querySelector('textarea,button,.layui-upload,.editor') || childrenMatching(row, '.layui-form-label').length !== 1 || row.querySelectorAll('input:not([type=hidden]),select').length > 2) row.classList.add('studio-field-wide');
      });
      childrenMatching(panel, ':not(.layui-form-item):not(input):not(script)').forEach(function (node) { node.classList.add('studio-field-wide'); });
    });
  }
  var dialogCloseObserver;
  var enhancedCloseControls = new WeakSet();
  function improveDialogCloseLabels() {
    if (dialogCloseObserver || !document.body) return;
    function labelClose(control) {
      if (!control.hasAttribute('aria-label')) control.setAttribute('aria-label', string('close', 'Close dialog'));
      if (control.matches('a[href],button,input[type=button],input[type=submit]')) return;
      if (!control.hasAttribute('role')) control.setAttribute('role', 'button');
      if (!control.hasAttribute('tabindex')) control.setAttribute('tabindex', '0');
      if (enhancedCloseControls.has(control)) return;
      enhancedCloseControls.add(control);
      control.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();
        if (!event.repeat) control.click();
      });
    }
    document.querySelectorAll('.layui-layer-close').forEach(labelClose);
    dialogCloseObserver = new MutationObserver(function (records) {
      records.forEach(function (record) {
        record.addedNodes.forEach(function (node) {
          if (node.nodeType !== 1) return;
          if (node.matches('.layui-layer-close')) labelClose(node);
          node.querySelectorAll('.layui-layer-close').forEach(labelClose);
        });
      });
    });
    dialogCloseObserver.observe(document.body, { childList: true, subtree: true });
  }
  function init() {
    improveDialogCloseLabels();
    var page = document.querySelector('.page-container');
    if (!page || page.dataset.studioReady) return;
    page.dataset.studioReady = 'true';
    if (page.matches('.admin-vod-info,.studio-vod-editor')) return;
    var existingTitle = page.querySelector('h1,.ax-page-head,.ax-editor-head,.db-hero,.admin-editor-return-toolbar,[class*="editor-return"],[class*="editor-toolbar"]');
    if (!existingTitle && !page.closest('#adminShell')) {
      // document.title is "<page> MIDDOT <brand>" (head.html); an empty page title gives only the brand: then no h1.
      var dot = String.fromCharCode(183);
      var parts = document.title.split(' ' + dot + ' ');
      if (parts.length < 2) parts = document.title.split(' - ');
      var title = parts.length > 1 ? parts[0].replace(/Management$/i, '').trim() : '';
      if (title && title.charAt(0) !== '-' && title.charAt(0) !== dot) {
        var header = element('header', 'studio-page-header');
        header.appendChild(element('h1', '', title));
        page.insertBefore(header, page.firstChild);
      }
    }
    improveFilters(page); improveActions(page);
    page.querySelectorAll('form').forEach(improveForm);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
}());
