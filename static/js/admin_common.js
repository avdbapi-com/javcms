/*
 * This file is part of JavCMS - https://javcms.net
 * Based on MacCMS 10 (Apache-2.0) by magicblack, changed by JavCMS.
 * JavCMS changes: Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors,
 * JavCMS Community License 1.0. The MacCMS parts stay under the Apache License 2.0.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
String.prototype.trim=function(){
    return this.replace(/(^[\s\u3000]*)|([\s\u3000]*$)/g, "");
}
String.prototype.ltrim=function(){
    return this.replace(/(^\s*)/g, "");
}
String.prototype.rtrim=function(){
    return this.replace(/(\s*$)/g, "");
}
String.prototype.replaceAll  = function(s1,s2){
    return this.replace(new RegExp(s1,"gm"),s2);
}

/* Shared admin page behaviour (owner: shell builder, ADMIN-IA-BRIEF.md D41).
   Every confirm goes through AxUX.confirm (kit, static/js/admin_ux.js): title, red Confirm, Cancel focused first (F8).
   Element attributes read here: data-ax-confirm (message, "{n}" = ticked rows), data-ax-confirm-title, data-ax-typed
   (word to type), data-ax-danger="0" (blue Confirm), legacy confirm="..." / tips="...". */
function axT(key, vars) {
    return window.AxUX ? window.AxUX.t(key, vars) : key;
}
function axToast(text, kind) {
    if (window.AxUX) { window.AxUX.toast(text, kind || 'ok'); } else if (window.layui && layui.layer) { layui.layer.msg(text); }
}
function axTicked() {
    return $('.checkbox-ids:checked').length;
}
/* Split a write link into {url, data}: the __token__ of the query moves into the POST body, so the token never
   travels in a request URL (server logs, Referer). REVIEW-admin-security-r3 P3-3. */
function axPostParts(href, extra) {
    var data = $.extend({}, extra || {});
    var url = String(href || '');
    var hash = '';
    var h = url.indexOf('#');
    if (h >= 0) { hash = url.slice(h); url = url.slice(0, h); }
    var q = url.indexOf('?');
    if (q >= 0) {
        var keep = [];
        url.slice(q + 1).split('&').forEach(function (pair) {
            if (!pair) { return; }
            var k = pair.split('=')[0];
            if (k === '__token__') {
                try { data.__token__ = decodeURIComponent(pair.slice(k.length + 1).replace(/\+/g, ' ')); } catch (e) { data.__token__ = pair.slice(k.length + 1); }
            } else {
                keep.push(pair);
            }
        });
        url = url.slice(0, q) + (keep.length ? '?' + keep.join('&') : '');
    }
    return { url: url + hash, data: data };
}
/* Ask before an action. opts: {el, count, message, title, typed, danger}. Resolves true / false.
   r5: one row uses data-ax-msg-one (else the kit's singular text); the confirm button says what the action does
   (data-ax-confirm-ok, else the action's own label such as "Delete" or "Move"), not a generic "Confirm". */
function axLabelOf($el) {
    var label = $.trim(($el.attr('aria-label') || $el.text() || '').replace(/\s+/g, ' '));
    return label.replace(/\s*(…|\.\.\.)\s*$/, '');
}
function axAsk(opts) {
    opts = opts || {};
    var $el = opts.el ? $(opts.el) : $();
    var message = $el.attr('data-ax-confirm') || opts.message || $el.attr('confirm') || $el.attr('tips') || axT('confirm_default');
    var count = typeof opts.count === 'number' ? opts.count : axTicked();
    var one = $el.attr('data-ax-msg-one') || '';
    if (count === 1 && one) { message = one; }
    if (!window.AxUX) {
        return Promise.resolve(window.confirm(String(message).replace('{n}', count)));
    }
    var danger = $el.attr('data-ax-danger') === '0' ? false : (opts.danger !== false);
    var label = axLabelOf($el);
    return window.AxUX.confirm({
        title: $el.attr('data-ax-confirm-title') || opts.title || label || axT('confirm_title'),
        message: message,
        typed: $el.attr('data-ax-typed') || opts.typed || undefined,
        confirmLabel: $el.attr('data-ax-confirm-ok') || label || undefined,
        danger: danger,
        count: count
    });
}
/* The address the server sent back is this same page: treat it as "stay here" (F24). */
function axIsSamePage(url) {
    if (!url) { return true; }
    try {
        var a = new URL(url, location.href), b = new URL(location.href);
        return a.origin === b.origin && a.pathname === b.pathname && a.search === b.search;
    } catch (e) { return false; }
}
function axNow() {
    var d = new Date();
    return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
}

layui.define(['element', 'form'], function(exports) {
    var $ = layui.jquery,element = layui.element, layer = layui.layer, form = layui.form;

    form.render();

    /* Lock screen (ADMIN-IA-BRIEF 4.6): the server answers {code:1001, locked:1} while locked; the shell shows its overlay. */
    $(document).ajaxSuccess(function (event, xhr) {
        var res = xhr && xhr.responseJSON;
        if (res && res.locked) {
            try { window.top.postMessage({ax: 'locked'}, location.origin); } catch (e) { /* standalone page */ }
        }
    });

    if (typeof(LAYUI_OFFSET) == 'undefined') {
        layer.config({offset:'60px'});
    } else {
        layer.config({offset:LAYUI_OFFSET+'px'});
    }

    /* Open a selection dialog. */
    $(document).on('click', '.j-select', function() {
        var that = $(this);
        var _url = that.attr('data-href'),
            _title = that.attr('data-title'),
            _width = that.attr('data-width') ? that.attr('data-width')+'' : 750,
            _height = that.attr('data-height') ? that.attr('data-height')+'' : 500,
            _checkbox = that.attr('data-checkbox');
        // r7 repair (function r6 P3-3): post only what the picker needs (the ticked ids and the admin token), never the
        // whole list form: 1000 rows made more than 1000 input variables and PHP dropped the rest.
        var host = that.parents('form')[0] ? that.parents('form') : $('#pageListForm');
        var query = {};
        var tok = host.find('input[name="__token__"]').first().val() || $('#ax-list-token').val() || '';
        if (tok) { query.__token__ = tok; }
        // F53: say why nothing happens instead of failing silently.
        if (_checkbox && axTicked() <= 0) {
            axToast(axT('select_first'), 'warn');
            return false;
        }
        if (_checkbox) {
            query.ids = host.find('.checkbox-ids:checked').map(function () { return this.value; }).get().join(',');
        }
        $.post(_url, query, function(res) {
            layer.closeAll('dialog');
            layer.open({type:1, title:_title, content:res, area: [_width+'px', _height+'px']});
            form.render('select');
        });
        return false;
    });

    /* Open a page in an iframe dialog. */
    $(document).on('click', '.j-iframe', function() {
        var that = $(this),
            _url = that.attr('data-href'),
            _title = that.attr('data-title'),
            _width = that.attr('data-width') ? that.attr('data-width')+'' : '85%',
            _height = that.attr('data-height') ? that.attr('data-height')+'' : '80%',
            _full = that.attr('data-full'),
            _checkbox = that.attr('data-checkbox');

        if (!_url) {
            axToast(axT('request_failed'), 'error');
            return false;
        }
        if(_checkbox){
            if (axTicked() <= 0) {
                axToast(axT('select_first'), 'warn');
                return false;
            }
            var ids = [];
            $('.checkbox-ids:checked').each(function(index, item) {
                if(item.checked){
                    ids.push(item.value);
                }
            });
            var _ids = ids.join(',');
            _url = _url.indexOf('?') > -1 ? _url + '&ids='+_ids : _url + '?ids=' + _ids;
        }
        var _post = that.attr('data-ax-post') === '1';
        var lay = layer.open({type:2, title:_title, content: _post ? 'about:blank' : _url, area: [_width+'', _height+''], end: function () {
            var opener = that[0];
            if (!opener || !document.documentElement.contains(opener)) { return; }
            var closedMenu = opener.closest('details:not([open])');
            if (closedMenu) { opener = closedMenu.querySelector('summary') || opener; }
            if (!opener.matches('a[href],button,input,select,textarea,summary,[tabindex]')) { opener.setAttribute('tabindex', '-1'); }
            opener.focus();
        }});
        if(_full=='1'){
            layer.full(lay);
        }
        if (_post) { axPostIntoLayer(lay, _url); }
        return false;
    });

    /* r7 repair (security r6 P1-2): send the first step of a write job (Scrape URLs / Scrape content, Make pages) as a
       POST with the admin token into the iframe of dialog `lay`. The query of `url` becomes hidden fields; the token
       comes from the page (#ax-run-token, #ax-list-token or the list form) and never goes into a URL. */
    function axPostIntoLayer(lay, url) {
        var frame = $('#layui-layer' + lay).find('iframe')[0];
        if (!frame) { return; }
        var i = url.indexOf('?');
        var form = document.createElement('form');
        form.method = 'post';
        form.action = i < 0 ? url : url.slice(0, i);
        form.target = frame.name || ('layui-layer-iframe' + lay);
        form.hidden = true;
        var add = function (k, v) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = k;
            input.value = v;
            form.appendChild(input);
        };
        (i < 0 ? '' : url.slice(i + 1)).split('&').forEach(function (pair) {
            if (!pair) { return; }
            var j = pair.indexOf('=');
            var k = j < 0 ? pair : pair.slice(0, j);
            var v = j < 0 ? '' : pair.slice(j + 1);
            try { k = decodeURIComponent(k.replace(/\+/g, ' ')); v = decodeURIComponent(v.replace(/\+/g, ' ')); } catch (err) { return; }
            if (k && k !== '__token__') { add(k, v); }
        });
        add('__token__', $('#ax-run-token').val() || $('#ax-list-token').val() || $('#pageListForm input[name="__token__"]').first().val() || '');
        document.body.appendChild(form);
        form.submit();
        setTimeout(function () { if (form.parentNode) { form.parentNode.removeChild(form); } }, 0);
    }

    /* Select or clear all rows. */
    form.on('checkbox(allChoose)', function(data) {
        var child = $(data.elem).parents('table').find('tbody input.checkbox-ids');
        child.each(function(index, item) {
            item.checked = data.elem.checked;
        });
        form.render('checkbox');
    });

    /* Save changes from a status switch. */
    form.on('switch(switchStatus)', function(data) {
        var that = $(this), status = 0;
        if (!that.attr('data-href')) {
            axToast(axT('request_failed'), 'error');
            return false;
        }
        if (this.checked) {
            status = 1;
        }
        // A status change is a write: POST with the token in the body (security r3 P3-3).
        var req = axPostParts(that.attr('data-href'), {val: status});
        $.post(req.url, req.data, function(res) {
            axToast(res.msg, res.code == 1 ? 'ok' : 'error');
            if (res.code == 0) {
                that.trigger('click');
                form.render('checkbox');
            }
        }).fail(function () { axToast(axT('request_failed'), 'error'); });
    });

    /* After a save that stays on the page, get a fresh form token without reloading (the old token is used up). */
    function refreshToken(_form) {
        var inputs = $('input[name="__token__"]');
        if (!inputs.length) { return; }
        $.ajax({ type: 'GET', url: location.href, dataType: 'text', cache: false }).done(function (html) {
            // An AJAX GET of an admin page comes back as a JSON string of the HTML.
            if (html && html.charAt(0) === '"') { try { html = JSON.parse(html); } catch (e) { /* keep text */ } }
            var m = /name="__token__"[^>]*value="([^"]+)"/.exec(html) || /value="([^"]+)"[^>]*name="__token__"/.exec(html);
            if (m && m[1]) { inputs.val(m[1]); }
        });
    }

    /* Submit the form and handle the response. */
    form.on('submit(formSubmit)', function(data) {
        var that = $(this),
            _form = '',
            _child = !that.attr('data-child') ? 'no' : that.attr('data-child'),
            refresh = !that.attr('refresh') ? 'yes' : that.attr('refresh');

        if (that.attr('data-form')) {
            _form = $(that.attr('data-form'));
        } else {
            _form = that.parents('form');
        }
        if (!_form.length) {
            _form = that.closest('form');
        }
        // Copy CKEditor content into the form before submission.
        if (typeof(CKEDITOR) != 'undefined') {
            for (var instance in CKEDITOR.instances) {
                CKEDITOR.instances[instance].updateElement();
            }
        }
        var loading = layer.load(2);
        $.ajax({
            type: "POST",
            url: _form.attr('action'),
            data: _form.serialize(),
            success: function(res) {
                layer.close(loading);
                var gotToken = !!(res && res.data && res.data.__token__);
                if (gotToken) {
                    _form.find('input[name="__token__"]').val(res.data.__token__);
                }
                if (res.code != 1) {
                    axToast(res.msg, 'error');
                    return;
                }
                if (refresh != 'yes') {
                    layer.closeAll();
                    axToast(res.msg, 'ok');
                    if (typeof onSubmitResult === 'function') { onSubmitResult(res); }
                    return;
                }
                if (_child == 'true') {
                    // Editors in a dialog: close it and refresh the list behind it.
                    axToast(res.msg, 'ok');
                    if (window.AxUX) { window.AxUX.markClean(); }
                    setTimeout(function () {
                        // F19: layer.getFrameIndex('') runs $('#') and throws; only ask when this page is a named dialog frame.
                        var inDialog = window.parent !== window && !!window.name;
                        var frameIndex = inDialog && parent.layer && typeof parent.layer.getFrameIndex === 'function' ? parent.layer.getFrameIndex(window.name) : null;
                        parent.location.reload();
                        if (frameIndex !== null && frameIndex !== undefined) {
                            parent.layer.close(frameIndex);
                        }
                    }, 600);
                    return;
                }
                if (!axIsSamePage(res.url)) {
                    if (window.AxUX) { window.AxUX.markClean(); }
                    location.href = res.url;
                    return;
                }
                // F24: settings pages stay where they are: toast 2.5 s + "Saved HH:MM" in the save bar, no reload.
                axToast(res.msg, 'ok');
                if (window.AxUX) { window.AxUX.markClean(); }
                var status = _form.find('.ax-savebar__status');
                if (!status.length) { status = $('.ax-savebar__status').first(); }
                status.text(axT('saved_at', axNow()));
                if (!gotToken) { refreshToken(_form); }
            },
            error: function (xhr) {
                layer.close(loading);
                axToast(axT('request_failed') + (xhr && xhr.status ? ' (HTTP ' + xhr.status + ')' : ''), 'error');
            }
        });
        return false;
    });

    /* Confirm deletion, then remove the deleted row. */
    $(document).on('click', '.j-tr-del', function() {
        var that = $(this),
            href = !that.attr('data-href') ? that.attr('href') : that.attr('data-href');
        if (!href || href.indexOf('javascript') === 0) {
            axToast(axT('request_failed'), 'error');
            return false;
        }
        axAsk({el: that, count: 1, message: that.attr('data-ax-confirm') ? undefined : axT('cannot_undo')}).then(function (yes) {
            if (!yes) { return; }
            // A row delete is a write: POST with the token in the body (security r3 P3-3).
            var req = axPostParts(href);
            $.post(req.url, req.data, function(res){
                axToast(res.msg, res.code == 1 ? 'ok' : 'error');
                if (res.code == 1) {
                    that.parents('tr').remove();
                    that.parents('.tr').remove();
                }
            }).fail(function () { axToast(axT('request_failed'), 'error'); });
        });
        return false;
    });

    /* Run an action through an AJAX request. */
    $(document).on('click', '.j-ajax', function() {
        var that = $(this),
            href = !that.attr('data-href') ? that.attr('href') : that.attr('data-href'),
            refresh = !that.attr('refresh') ? 'yes' : that.attr('refresh');
        if (!href || href.indexOf('javascript') === 0) {
            axToast(axT('request_failed'), 'error');
            return false;
        }
        // F4: clearing the cache never reloads a page (open tabs keep typed text).
        var isClear = /\/index\/clear(\.html)?(\?|$)/.test(href);
        var run = function () {
            var loading = layer.load(2);
            $.ajax({
                type: 'GET',
                url: href,
                dataType: 'json',
                timeout: 15000
            }).done(function(res) {
                axToast(res.msg, res.code == 1 ? 'ok' : 'error');
                if (isClear || refresh != 'yes' || res.code != 1) { return; }
                setTimeout(function () {
                    if (res.url && !axIsSamePage(res.url)) {
                        location.href = res.url;
                    } else {
                        location.reload();
                    }
                }, 900);
            }).fail(function(xhr, status) {
                axToast(axT('request_failed') + (status !== 'timeout' && xhr.status ? ' (HTTP ' + xhr.status + ')' : ''), 'error');
            }).always(function() {
                layer.close(loading);
            });
        };
        if (that.attr('confirm') || that.attr('data-ax-confirm')) {
            axAsk({el: that, count: axTicked()}).then(function (yes) { if (yes) { run(); } });
        } else {
            run();
        }
        return false;
    });

    $('.j-search').click(function(){
        var that = $(this);
        that.parents('form').attr('method','get');
        that.parents('form').submit();
    });

    /* r5 repair (security r5 P1-1): an import from an API source writes rows, so its buttons post. data-ax-post-run holds
       the job settings as a query string; the click sends them with the admin token as a real form POST (the import page
       shows its progress as it runs), never as a GET link. data-ax-need-ids="1": the ticked rows go along as ids. */
    $(document).on('click', '[data-ax-post-run]', function (e) {
        e.preventDefault();
        var that = $(this);
        var fields = {};
        String(that.attr('data-ax-post-run') || '').split('&').forEach(function (pair) {
            if (!pair) { return; }
            var i = pair.indexOf('=');
            var k = i < 0 ? pair : pair.slice(0, i);
            var v = i < 0 ? '' : pair.slice(i + 1);
            try { k = decodeURIComponent(k.replace(/\+/g, ' ')); v = decodeURIComponent(v.replace(/\+/g, ' ')); } catch (err) { return; }
            if (k && k !== '__token__') { fields[k] = v; }
        });
        if (that.attr('data-ax-need-ids') === '1') {
            var ids = [];
            $('.checkbox-ids:checked').each(function () { ids.push(this.value); });
            if (!ids.length) {
                axToast(axT('select_first'), 'warn');
                return false;
            }
            fields.ids = ids.join(',');
        }
        fields.__token__ = $('#ax-run-token').val() || $('#ax-list-token').val() || '';
        var form = document.createElement('form');
        form.method = 'post';
        form.action = that.attr('data-ax-action') || '';
        form.hidden = true;
        Object.keys(fields).forEach(function (k) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = k;
            input.value = fields[k];
            form.appendChild(input);
        });
        document.body.appendChild(form);
        that.prop('disabled', true).attr('aria-busy', 'true');
        form.submit();
        return false;
    });

    /* Run an action on the selected rows (F8: check the selection first, then confirm with the real count). */
    $(document).on('click', '.j-page-btns', function(){
        var that = $(this),
            href = that.attr('href') && that.attr('href').indexOf('javascript') !== 0 ? that.attr('href') : that.attr('data-href'),
            _checkbox = !that.attr('data-checkbox') ? 'yes' : that.attr('data-checkbox'),
            _ajax = !that.attr('data-ajax') ? 'yes' : that.attr('data-ajax');
        if (!href) {
            axToast(axT('request_failed'), 'error');
            return false;
        }
        var count = axTicked();
        if (_checkbox == 'yes' && count <= 0) {
            axToast(axT('select_first'), 'warn');
            return false;
        }
        var code = function() {
            var _ids = '';
            if (_checkbox == 'yes') {
                var ids = [];
                $('.checkbox-ids:checked').each(function(index, item) {
                    if(item.checked){
                        ids.push(item.value);
                    }
                });
                _ids = ids.join(',');
            }
            if (_ajax == 'yes') {
                var query = that.parents('form')[0] ? that.parents('form').serialize() : $('#pageListForm').serialize();
                var loading = layer.load(2);
                $.post(href, query, function (res) {
                    layer.close(loading);
                    axToast(res.msg, res.code != 0 ? 'ok' : 'error');
                    if (res.code != 0) {
                        setTimeout(function () { location.reload(); }, 900);
                    }
                }).fail(function () { layer.close(loading); axToast(axT('request_failed'), 'error'); });
            } else {
                location.href = href.indexOf('?') == -1 ? href + '?ids=' + _ids : href + '&ids=' + _ids;
            }
        };
        if (that.hasClass('confirm') || that.attr('data-ax-confirm')) {
            // Buttons that do not use the ticked rows (e.g. "remove duplicates") must not say "0 selected".
            var plain = _checkbox != 'yes' && !that.attr('data-ax-confirm') && !that.attr('confirm');
            axAsk({el: that, count: count, message: plain ? axT('cannot_undo') : undefined}).then(function (yes) { if (yes) { code(); } });
        } else {
            code();
        }
        return false;
    });


    exports('global', {});
});

/* xss-fix-A (REVIEW-admin-security-r8 P1-1): the list "Select and return" button carries the target field name in
   data-ax-select-return (allow-listed in PHP) instead of an inline onclick, so a request value never runs as script. */
var AX_SELECT_FIELD = /^[A-Za-z0-9_\[\]-]{1,64}$/;
$(document).on('click', '[data-ax-select-return]', function () {
    var name = String(this.getAttribute('data-ax-select-return') || '');
    var host = window.parent;
    if (!AX_SELECT_FIELD.test(name) || !host || host === window || typeof host.onSelectResult !== 'function') { return; }
    host.onSelectResult(name, $('.checkbox-ids:checked'));
});

function onSelectResult(input,obj){
    var added = 0;
    var s1 ='',s2='';
    input = String(input || '');
    if (!AX_SELECT_FIELD.test(input)) { return; }
    $(obj).each(function(index, item) {
        if(item.checked){
            s1 = $("input[name='"+input+"']").val();
            s2 = ','+s1+',';
            if(s2.indexOf(','+item.value+',') ==-1){
                if(s1.length > 0 && s1.substring(s1.length-1)!=','){
                    s1 += ',';
                }
                s1 += item.value;
                $("input[name='"+input+"']").val( s1 );
                added++;
            }
        }
    });
    axToast(axT('selected', {n: added}), 'ok');
}

function rndNum(under, over){
    switch(arguments.length){
        case 1: return parseInt(Math.random()*under+1);
        case 2: return parseInt(Math.random()*(over-under+1) + under);
        default: return 0;
    }
}

function changeParam(url,name,value)
{
    var newUrl="";
    var reg = new RegExp("(^|&)"+ name +"=([^&]*)(&|$)");
    var tmp = name + "=" + value;
    if(url.match(reg) != null) {
        newUrl= url.replace(reg,'&'+tmp + '&');
    }
    else {
        if(url.match("[\?]")) {
            newUrl= url + "&" + tmp;
        }
        else{
            newUrl= url + "?" + tmp;
        }
    }
    return newUrl;
}

function getDataTime(ts,ty) {
    if(ts<1){
        return '';
    }
    var t,y,m,d,h,i,s,r;
    t = ts ? new Date(ts*1000) : new Date();
    y = t.getFullYear();
    m = t.getMonth()+1;
    d = t.getDate();
    h = t.getHours();
    i = t.getMinutes();
    s = t.getSeconds();
    r = y+'-'+(m<10?'0'+m:m) + '-' + (d<10?'0'+d:d);

    if(ty==undefined || ty==''){
        r += ' ' + (h<10?'0'+h:h)  + ':' + (i<10?'0'+i:i) + ':' + (s<10?'0'+s:s)
    }
    return r;
}

function mac_url_img(url)
{
    url = url.replace('mac:','http:');
    if (url.indexOf("http") == -1 || url.indexOf("//") == -1){
        url = ROOT_PATH+"/"+url;
    }
    else if(UPLOAD_IMG_KEY !='' && UPLOAD_IMG_API !=''){
        var reg=new RegExp(UPLOAD_IMG_KEY, "i");
        if(reg.test(url)!=false){
            url = UPLOAD_IMG_API + url;
        }
    }
    return url;
}
