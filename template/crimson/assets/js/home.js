/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Based on MacCMS 10 (Apache-2.0) by magicblack, changed by JavCMS.
 * JavCMS changes: Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors,
 * JavCMS Community License 1.0. The MacCMS parts stay under the Apache License 2.0.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */

String.prototype.replaceAll  = function(s1,s2){ return this.replace(new RegExp(s1,"gm"),s2); }
String.prototype.trim=function(){ return this.replace(/(^\s*)|(\s*$)/g, ""); }
var base64EncodeChars="ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";var base64DecodeChars=new Array(-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,62,-1,-1,-1,63,52,53,54,55,56,57,58,59,60,61,-1,-1,-1,-1,-1,-1,-1,0,1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,-1,-1,-1,-1,-1,-1,26,27,28,29,30,31,32,33,34,35,36,37,38,39,40,41,42,43,44,45,46,47,48,49,50,51,-1,-1,-1,-1,-1);function base64encode(str){var out,i,len;var c1,c2,c3;len=str.length;i=0;out="";while(i<len){c1=str.charCodeAt(i++)&0xff;if(i==len){out+=base64EncodeChars.charAt(c1>>2);out+=base64EncodeChars.charAt((c1&0x3)<<4);out+="==";break}c2=str.charCodeAt(i++);if(i==len){out+=base64EncodeChars.charAt(c1>>2);out+=base64EncodeChars.charAt(((c1&0x3)<<4)|((c2&0xF0)>>4));out+=base64EncodeChars.charAt((c2&0xF)<<2);out+="=";break}c3=str.charCodeAt(i++);out+=base64EncodeChars.charAt(c1>>2);out+=base64EncodeChars.charAt(((c1&0x3)<<4)|((c2&0xF0)>>4));out+=base64EncodeChars.charAt(((c2&0xF)<<2)|((c3&0xC0)>>6));out+=base64EncodeChars.charAt(c3&0x3F)}return out}function base64decode(str){var c1,c2,c3,c4;var i,len,out;len=str.length;i=0;out="";while(i<len){do{c1=base64DecodeChars[str.charCodeAt(i++)&0xff]}while(i<len&&c1==-1);if(c1==-1)break;do{c2=base64DecodeChars[str.charCodeAt(i++)&0xff]}while(i<len&&c2==-1);if(c2==-1)break;out+=String.fromCharCode((c1<<2)|((c2&0x30)>>4));do{c3=str.charCodeAt(i++)&0xff;if(c3==61)return out;c3=base64DecodeChars[c3]}while(i<len&&c3==-1);if(c3==-1)break;out+=String.fromCharCode(((c2&0XF)<<4)|((c3&0x3C)>>2));do{c4=str.charCodeAt(i++)&0xff;if(c4==61)return out;c4=base64DecodeChars[c4]}while(i<len&&c4==-1);if(c4==-1)break;out+=String.fromCharCode(((c3&0x03)<<6)|c4)}return out}function utf16to8(str){var out,i,len,c;out="";len=str.length;for(i=0;i<len;i++){c=str.charCodeAt(i);if((c>=0x0001)&&(c<=0x007F)){out+=str.charAt(i)}else if(c>0x07FF){out+=String.fromCharCode(0xE0|((c>>12)&0x0F));out+=String.fromCharCode(0x80|((c>>6)&0x3F));out+=String.fromCharCode(0x80|((c>>0)&0x3F))}else{out+=String.fromCharCode(0xC0|((c>>6)&0x1F));out+=String.fromCharCode(0x80|((c>>0)&0x3F))}}return out}function utf8to16(str){var out,i,len,c;var char2,char3;out="";len=str.length;i=0;while(i<len){c=str.charCodeAt(i++);switch(c>>4){case 0:case 1:case 2:case 3:case 4:case 5:case 6:case 7:out+=str.charAt(i-1);break;case 12:case 13:char2=str.charCodeAt(i++);out+=String.fromCharCode(((c&0x1F)<<6)|(char2&0x3F));break;case 14:char2=str.charCodeAt(i++);char3=str.charCodeAt(i++);out+=String.fromCharCode(((c&0x0F)<<12)|((char2&0x3F)<<6)|((char3&0x3F)<<0));break}}return out}

var MAC={
    'NativePage': function (section, action, values) {
        var allowed = {user: ['login', 'index'], gbook: ['index']};
        if (!allowed[section] || allowed[section].indexOf(action) < 0) return false;
        var target;
        try {
            var root = new URL(String(maccms.path || '/'), location.origin + '/');
            if (root.origin !== location.origin || !/^https?:$/.test(root.protocol) || root.username || root.password || root.search || root.hash) return false;
            var route = section + '/' + action, urls = maccms.template_urls || {};
            var mapped = urls[section + '_' + action];
            target = new URL(mapped || root.pathname.replace(/\/+$/, '') + '/index.php?s=' + route, location.href);
            if (target.origin !== location.origin || !/^https?:$/.test(target.protocol) || target.username || target.password) return false;
        } catch (error) { return false; }
        if (section === 'gbook' && values) {
            if (/^\d+$/.test(String(values.id || ''))) target.searchParams.set('id', String(values.id));
            if (values.name) target.searchParams.set('name', String(values.name).slice(0, 500));
        }
        var locale = new URL(location.href).searchParams.get('ui_lang');
        if (['en','vi','th','id','ko','zh','ja','fr','de','es','pt','ru','ar','tr','hi','ms','tl'].indexOf(locale) >= 0) target.searchParams.set('ui_lang', locale);
        window.location.assign(target.href);
        return true;
    },

    'FormPost': function (section, data, success) {
        if (['comment', 'gbook'].indexOf(section) < 0) return false;
        var routes = maccms.template_urls || {}, tokenUrl, saveUrl;
        if (typeof routes[section + '_token'] !== 'string' || typeof routes[section + '_save'] !== 'string') return false;
        try {
            tokenUrl = new URL(routes[section + '_token'], location.href);
            saveUrl = new URL(routes[section + '_save'], location.href);
            if (tokenUrl.origin !== location.origin || saveUrl.origin !== location.origin) return false;
        } catch (error) { return false; }
        var failure = function () { MAC.Pop.Msg(100, 20, MAC.TemplateMessage('ui.connection_failed_try_again', 'Connection failed. Try again.'), 2000); };
        $.ajax({url:tokenUrl.href, type:'get', dataType:'json', cache:false, success:function (result) {
            if (!result || result.code !== 1 || typeof result.__token__ !== 'string' || !result.__token__) { failure(); return; }
            var values = new URLSearchParams(data);
            values.delete('__token__');
            values.set('__token__', result.__token__);
            $.ajax({url:saveUrl.href, type:'post', dataType:'json', data:values.toString(), success:success, error:failure});
        }, error:failure});
        return true;
    },

    'TemplateMessage': function (key, fallback) { var messages = window.TemplateMessages || {}; return typeof messages[key] === 'string' ? messages[key] : fallback; },
    'Url': document.URL,
    'Title': document.title,
    'UserAgent' : function(){
        var ua = navigator.userAgent;//navigator.appVersion
        return {
            'mobile': !!ua.match(/AppleWebKit.*Mobile.*/), //
            'ios': !!ua.match(/\(i[^;]+;( U;)? CPU.+Mac OS X/), //
            'android': ua.indexOf('Android') > -1 || ua.indexOf('Linux') > -1, //
            'iPhone': ua.indexOf('iPhone') > -1 || ua.indexOf('Mac') > -1, //
            'iPad': ua.indexOf('iPad') > -1, //
            'trident': ua.indexOf('Trident') > -1, //
            'presto': ua.indexOf('Presto') > -1, //
            'webKit': ua.indexOf('AppleWebKit') > -1, //
            'gecko': ua.indexOf('Gecko') > -1 && ua.indexOf('KHTML') == -1, //
        };
    }(),
    'Copy': function (value) {
        var text = String(value);
        function notify(key, fallback) { if (window.TemplateUI) TemplateUI.message(MAC.TemplateMessage(key, fallback)); }
        function manual() {
            var input = document.createElement('textarea');
            input.value = text; input.setAttribute('readonly', '');
            input.style.position = 'fixed'; input.style.opacity = '0';
            var previous = document.activeElement;
            document.body.appendChild(input); input.select();
            var copied = false;
            try { copied = document.execCommand('copy'); } catch (_) {}
            input.remove(); if (previous && previous.focus) previous.focus();
            if (copied) notify('ui.link_copied', 'Link copied');
            else if (window.TemplateUI) TemplateUI.message(MAC.TemplateMessage('copy_manually', 'Select and copy this link manually.') + ' ' + text);
        }
        if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(function () { notify('ui.link_copied', 'Link copied'); }, manual);
        else manual();
    },
    'Home': function () {
        if (window.TemplateUI) TemplateUI.message(MAC.TemplateMessage('homepage_browser_settings', 'Set this page as your homepage in your browser settings.'));
    },
    'Fav': function () {
        if (window.TemplateUI) TemplateUI.message(MAC.TemplateMessage('bookmark_browser_menu', 'Use your browser menu to bookmark this page.'));
    },
    'Open': function(u,w,h){
        window.open(u,'macopen1','toolbars=0, scrollbars=0, location=0, statusbars=0,menubars=0,resizable=yes,width='+w+',height='+h+'');
    },
    'Cookie': {
        'Set': function(name,value,days){
            var exp = new Date();
            exp.setTime(exp.getTime() + days*24*60*60*1000);
            var arr=document.cookie.match(new RegExp("(^| )"+name+"=([^;]*)(;|$)"));
            document.cookie=name+"="+encodeURIComponent(value)+";path=/;expires="+exp.toUTCString();
        },
        'Get': function(name){
            var arr = document.cookie.match(new RegExp("(^| )"+name+"=([^;]*)(;|$)"));
            if(arr != null){ return decodeURIComponent(arr[2]); return null; }
        },
        'Del': function(name){
            var exp = new Date();
            exp.setTime(exp.getTime()-1);
            var cval = this.Get(name);
            if(cval != null){ document.cookie = name+"="+encodeURIComponent(cval)+";path=/;expires="+exp.toUTCString(); }
        }
    },
    'GoBack':function(){
        var ldghost=document.domain;
        if(document.referrer.indexOf(ldghost)>0) {
            history.back();
        }
        else{
            window.location ="//"+ldghost;
        }
    },
    'Adaptive':function(){
        if(maccms.mob_status=='1' && maccms.url != maccms.wapurl){
            if(document.domain ==maccms.url && MAC.UserAgent.mobile){
                    location.href = location.href.replace(maccms.url,maccms.wapurl);
            }
            else if(document.domain ==maccms.wapurl && !MAC.UserAgent.mobile){
                location.href = location.href.replace(maccms.wapurl,maccms.url);
            }
        }
    },
    'CheckBox':{
        'All':function(n){
            $("input[name='"+n+"']").each(function() {
                this.checked = true;
            });
        },
        'Other':function(n){
            $("input[name='"+n+"']").each(function() {
                this.checked = !this.checked;
            });
        },
        'Count':function(n){
            var res=0;
            $("input[name='"+n+"']").each(function() {
                if(this.checked){ res++; }
            });
            return res;
        },
        'Ids':function(n){
            var res=[];
            $("input[name='"+n+"']").each(function() {
                if(this.checked){ res.push(this.value); }
            });
            return res.join(",");
        }
    },
    'Ajax':function(url,type,dataType,data,sfun,efun,cfun){
        type=type||'get';
        dataType=dataType||'json';
        data=data||'';
        efun=efun||'';
        cfun=cfun||'';

        $.ajax({
            url:url,
            type:type,
            dataType:dataType,
            data:data,
            timeout: 5000,
            beforeSend:function(XHR){

            },
            error:function(XHR,textStatus,errorThrown){
                if(efun) efun(XHR,textStatus,errorThrown);
            },
            success:function(data){
                sfun(data);
            },
            complete:function(XHR, TS){
                if(cfun) cfun(XHR, TS);
            }
        })
    },
    'Qrcode': {'Init': function () {}},
    'Shorten': {'Init': function () {}, 'Get': function (url, call) { if (call) call({code: 0}); }},
    'Verify': {
        'Init': function(){
            MAC.Verify.Focus();
            MAC.Verify.Click();
        },
        'Focus': function(){//a
            $('body').off('focus.macVerify', '.mac_verify').on('focus.macVerify', '.mac_verify', function(){
                var input = $(this);
                input.removeClass('mac_verify');
                if (!input.next('img.mac_verify_img').length) input.after(MAC.Verify.Show());
            });
        },
        'Click': function(){//a
            $('body').off('click.macVerify', 'img.mac_verify_img').on('click.macVerify', 'img.mac_verify_img', function(){
                $(this).attr('src', maccms.path +'/index.php/verify/index.html?r='+Math.random());
            });
            $('body').off('keydown.macVerify', 'img.mac_verify_img,img.fed-user-code').on('keydown.macVerify', 'img.mac_verify_img,img.fed-user-code', function(event){
                if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); $(this).trigger('click'); }
            });
            // Account pages use a body keyup submit hook; refresh must not submit that form.
            $('body').off('keyup.macVerify', 'img.mac_verify_img,img.fed-user-code').on('keyup.macVerify', 'img.mac_verify_img,img.fed-user-code', function(event){
                if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); event.stopImmediatePropagation(); }
            });
        },
        'Refresh':function(){
            $('.mac_verify_img').attr('src', maccms.path +'/index.php/verify/index.html?r='+Math.random());
        },
        'Show':function(){
            return $('<img>').addClass('mac_verify_img').attr({src: maccms.path + '/index.php/verify/index.html?', title: MAC.TemplateMessage('not_to_see_change_one', 'Refresh verification code'), alt: MAC.TemplateMessage('verification_code', 'Verification code')}).attr({role: 'button', tabindex: '0', 'aria-label': MAC.TemplateMessage('not_to_see_change_one', 'Refresh verification code')}).prop('outerHTML');
        }
    },
    'PageGo':{
        'Init':function() {
            $('.mac_page_go').click(function () {
                var that =$(this);
                var url = that.attr('data-url');
                var total = parseInt(that.attr('data-total'));
                var sp = that.attr('data-sp');
                var page= parseInt($('#page').val());

                if(page>0&&(page<=total)){
                    url=url.replace(sp + 'PAGELINK',sp + page).replace('PAGELINK',page);
                    location.href=url;
                }
                return false;
            });
        }
    },
    'Hits': {
        'Init':function() {
            if($('.mac_hits').length==0){
                return;
            }
            var $that = $(".mac_hits");

            MAC.Ajax(maccms.path + '/index.php/ajax/hits?mid='+$that.attr("data-mid")+'&id='+$that.attr("data-id")+'&type=update','get','json','',function(r){
                if (r.code == 1) {
                    $(".mac_hits").each(function(i){
                        $type = $(".mac_hits").eq(i).attr('data-type');
                        if($type != 'insert'){
                            $(this).text(Number(r.data[$type]) || 0);
                        }
                    });
                }
            });

        }
    },
    'Score': {
        'Init':function(){
            if($('.mac_score').length==0){
                return;
            }
            $('body').on('click', '.score_btn', function(e){
                MAC.Score.Submit();
            });

            MAC.Ajax(maccms.path+'/index.php/ajax/score?mid='+ $('.mac_score').attr('data-mid') +'&id=' +$('.mac_score').attr('data-id'),'post','json','',function(r){
                MAC.Score.View(r);
            },function(){
                $(".mac_score").html(MAC.TemplateMessage('score_loading_failed', 'Score loading failed'));
            });

        },
        'Submit':function(){
            var $s = $('.mac_score').find("input[name='score']").val();
            MAC.Ajax(maccms.path+'/index.php/ajax/score?mid='+$('.mac_score').attr('data-mid')+'&id='+$('.mac_score').attr('data-id') + '&score='+ $s,'get','json','',function(r){
                MAC.Pop.Msg(100,20,r.msg,1000);
                if(r.code==1){
                    MAC.Score.View(r);
                }
            });
        },
        'View':function(r){
            $(".rating"+Math.floor(r.data.score)).attr('checked',true);
            $(".score_num").text(r.data.score_num);
            $(".score_all").text(r.data.score_all);
            $(".score_pjf").text(r.data.score);
        }
    },
    'Star': {
        'Init':function(){
            if($('.mac_star').length==0){
                return;
            }

            $('.mac_star').raty({
                starType: 'i',
                number: 5,
                numberMax : 5,
                half: true,
                score : function(){
                    return $(this).attr('data-score');
                },
                click: function(score, evt) {

                    MAC.Ajax(maccms.path+'/index.php/ajax/score?mid='+$('.mac_star').attr('data-mid')+'&id='+$('.mac_star').attr('data-id')+'&score='+(score*2),'get','json','',function(r){
                        if(json.status == 1){
                            $('.star_tips').text(Number(r.data.score) || 0);
                        }else{
                            $('.star_box').attr('title', r.msg);
                        }
                    },function(){
                        $('.star_box').attr('title', MAC.TemplateMessage('network_error', 'Network error'));

                    });

                }
            });
        }
    },
    'Digg': {
        'Init':function(){
            $('body').on('click', '.digg_link', function(e){
                var $that = $(this);
                if($that.attr("data-id")){

                    MAC.Ajax(maccms.path + '/index.php/ajax/digg.html?mid='+$that.attr("data-mid")+'&id='+$that.attr("data-id")+'&type='+$that.attr("data-type"),'get','json','',function(r){
                        $that.addClass('disabled');
                        if(r.code == 1){
                            if($that.attr("data-type")=='up'){
                                $that.find('.digg_num').text(Number(r.data.up) || 0);
                            }
                            else{
                                $that.find('.digg_num').text(Number(r.data.down) || 0);
                            }
                        }
                        else{
                            $that.attr('title', r.msg);
                        }
                    });

                }
            });
        }
    },
    'Gbook':{
        'Login':0,
        'Verify':0,
        'Init':function(){
            $('body').on('keyup', '.gbook_content', function(e){
                MAC.Remaining($(this),200,'.gbook_remaining')
            });
            $('body').on('focus', '.gbook_content', function(e){
                if(MAC.Gbook.Login==1 && MAC.User.IsLogin!=1){
                    MAC.User.Login();
                }
            });
            $('body').on('click', '.gbook_submit', function(e){
                MAC.Gbook.Submit();
            });
        },
        'Show':function($page){
            MAC.Ajax(maccms.path+'/index.php/gbook/index?page='+$page,'post','json','',function(r){
                $(".mac_gbook_box").html(r);
            },function(){
                $(".mac_gbook_box").html(MAC.TemplateMessage('comment_loading_failed', 'Comment loading failed, please refresh...'));

            });
        },
        'Submit':function(){
            if($(".gbook_content").val() == ''){
                MAC.Pop.Msg(100, 20, MAC.TemplateMessage('please_enter_comment', 'Please enter your comment!'), 1000);

                return false;
            }
            MAC.FormPost('gbook', $('.gbook_form').serialize(),function(r){
                MAC.Pop.Msg(100,20,r.msg,1000);
                if(r.code == 1){
                    location.reload();
                }
                else{
                    if(MAC.Gbook.Verify==1){
                        MAC.Verify.Refresh();
                    }
                }
            });
        },
        'Report':function(name,id){
            MAC.Pop.Show(400, 300, MAC.TemplateMessage('data_error', 'Data error'), maccms.path + '/index.php/gbook/report.html?id=' + id + '&name=' + encodeURIComponent(name), function(r)
{

            });
        }
    },
    'Search':{
        'Init':function(){
            $('.mac_search').click(function(){
                var that=$(this);
                var url = that.attr('data-href') ? that.attr('data-href') : maccms.path + '/index.php/vod/search.html';
                location.href = url + '?wd='+ encodeURIComponent($("#wd").val());
            });
        },
        'Submit':function(){

            return false;
        }
    },
    'Suggest':{
        'Init':function($obj,$mid,$jumpurl){
            try {
                $($obj).autocomplete(maccms.path + '/index.php/ajax/suggest?mid=' + $mid, {
                    inputClass: "mac_input",
                    resultsClass: "mac_results",
                    loadingClass: "mac_loading",
                    width: 175, scrollHeight: 300, minChars: 1, matchSubset: 0,
                    cacheLength: 10, multiple: false, matchContains: true, autoFill: false,
                    dataType: "json",
                    parse: function (r) {
                        if (r.code == 1) {
                            var parsed = [];
                            $.each(r['list'], function (index, row) {
                                row.url = r.url;
                                parsed[index] = {
                                    data: row
                                };
                            });
                            return parsed;
                        } else {
                            return {data: ''};
                        }
                    },
                    formatItem: function (row, i, max) {
                        return row.name;
                    },
                    formatResult: function (row, i, max) {
                        return row.text;
                    }
                }).result(function (event, data, formatted) {
                    $($obj).val(data.name);
                    location.href = data.url.replace('mac_wd', encodeURIComponent(data.name));
                });
            }
            catch(e){}
        }
    },
    'History': {
        'Limit': 10, 'Days': 7, 'Json': [],
        'Read': function () {
            var raw = MAC.Cookie.Get('mac_history');
            if (!raw) return [];
            try {
                // Accept only the old object's literal unquoted `log` key.
                var parsed = JSON.parse(raw.replace(/^\s*\{log:/, '{"log":'));
                var rows = Array.isArray(parsed) ? parsed : parsed.log;
                if (!Array.isArray(rows)) return [];
                return rows.filter(function (row) {
                    if (!row || typeof row.name !== 'string' || typeof row.link !== 'string') return false;
                    try { var url = new URL(row.link, location.href); return url.origin === location.origin && /^https?:$/.test(url.protocol); } catch (e) { return false; }
                }).slice(0, MAC.History.Limit);
            } catch (e) { return []; }
        },
        'Init': function () {
            if (!$('.mac_history').length) return;
            var history = MAC.History;
            var box = $('<dl>').addClass('mac_drop_box mac_history_box').hide();
            var clear = $('<button>').attr('type', 'button').text(MAC.TemplateMessage('history_clear', 'Clear')).on('click', history.Clear);
            box.append($('<dt>').append(clear));
            var rows = history.Read();
            if (!rows.length) box.append($('<dd>').text(MAC.TemplateMessage('history_empty', 'No browsing history')));
            rows.forEach(function (row) {
                box.append($('<dd>').append($('<a>').addClass('hx_title').attr('href', row.link).text(row.name)));
            });
            $('.mac_history').after(box).on('mouseenter focusin', function () { box.show(); });
            box.on('mouseleave', function () { box.hide(); });
            var item = $('.mac_history_set').first();
            if (item.attr('data-name')) history.Set(item.attr('data-name'), item.attr('data-link'), item.attr('data-pic'));
        },
        'Set': function (name, link, pic) {
            link = link || location.href;
            try { var url = new URL(link, location.href); if (url.origin !== location.origin || !/^https?:$/.test(url.protocol)) return false; } catch (e) { return false; }
            var rows = this.Read().filter(function (row) { return row.link !== link; });
            rows.unshift({name: String(name || '').slice(0, 300), link: link, pic: String(pic || '').slice(0, 2000)});
            this.Json = rows.slice(0, this.Limit);
            MAC.Cookie.Set('mac_history', JSON.stringify(this.Json), this.Days);
        },
        'Clear': function () { MAC.Cookie.Del('mac_history'); MAC.History.Json = []; $('.mac_history_box').empty(); }
    },
    'Ulog':{
        'Init':function(){
            MAC.Ulog.Set();
            MAC.Ulog.Click();

        },
        'Get':function(mid,id,type,page,limit,call){
            MAC.Ajax(maccms.path+'/index.php/user/ajax_ulog/?ac=list&mid='+mid+'&id='+id+'&type='+type+'&page='+page+'&limit='+limit,'get','json','',call);
        },
        'Set':function(){
            if($(".mac_ulog_set").attr('data-mid')){
                var $that = $(".mac_ulog_set");
                $.get(maccms.path+'/index.php/user/ajax_ulog/?ac=set&mid='+$that.attr("data-mid")+'&id='+$that.attr("data-id")+'&sid='+$that.attr("data-sid")+'&nid='+$that.attr("data-nid")+'&type='+$that.attr("data-type"));
            }
        },
        'Click':function(){
            $('body').on('click', 'a.mac_ulog', function(e){

                //a
                if(MAC.User.IsLogin == 0){
                    MAC.User.Login();
                    return;
                }

                var $that = $(this);
                if($that.attr("data-id")){
                    MAC.Ajax(maccms.path+'/index.php/user/ajax_ulog/?ac=set&mid='+$that.attr("data-mid")+'&id='+$that.attr("data-id")+'&type='+$that.attr("data-type"),'get','json','',function(r){
                        MAC.Pop.Msg(100,20,r.msg,1000);
                        if(r.code == 1){
                            $that.addClass('disabled');
                        }else{
                            $that.attr('title', r.msg);
                        }
                    });
                }
            });
        }
    },
    'Website':{
        'Referer':function() {
            if($('.mac_referer').length==0){
                return;
            }

            var url = document.referrer
                ,domain=''
                ,host = window.location.host
                ,reg = /^http(s)?:\/\/(.*?)\//i
                ,mc = url.match(reg);

            if(url=='' || url.indexOf(host)!=-1 || mc ==null){
                return;
            }
            domain = mc[2];
            MAC.Ajax(maccms.path + '/index.php/ajax/referer?domain='+encodeURIComponent(domain)+'&url='+encodeURIComponent(url)+'&type=update','get','json','',function(r){
                if (r.code == 1) {
                }
                console.log(r);
            });
        }
    },
    'User':{
        'BoxShow':0,
        'IsLogin':0,
        'UserId':'',
        'UserName':'',
        'GroupId':'',
        'GroupName':'',
        'Portrait':'',
        'Init':function(){
            if($('.mac_user').length >0){
                $('body').on('click', '.mac_user', function(e){
                    MAC.User.Login();
                });

                $('.mac_user').hover(function(e){
                    $('.mac_user_box').show();
                }, function(){
                    $('.mac_user_box').hover(function(){
                        MAC.User.BoxShow = 1;
                    }, function(){
                        MAC.User.BoxShow = 0;
                        $('.mac_user_box').hide();
                    });
                });
            }

            if(MAC.Cookie.Get('user_id') !=undefined && MAC.Cookie.Get('user_id')!=''){
                var url = maccms.path + '/index.php/user';
                MAC.User.UserId = MAC.Cookie.Get('user_id');
                MAC.User.UserName = MAC.Cookie.Get('user_name');
                MAC.User.GroupId = MAC.Cookie.Get('group_id');
                MAC.User.GroupName = MAC.Cookie.Get('group_name');
                MAC.User.Portrait = MAC.Cookie.Get('user_portrait');
                MAC.User.IsLogin = 1;

                if($('.mac_user').length >0) {
                    if ($('.mac_user').prop("outerHTML").substr(0, 2) == '<a') {
                        $('.mac_user').attr('href', url);
                        $('.mac_user').text(MAC.User.UserName);
                    }
                    else {
                        //$('.mac_user').html('<a class="mac_text" href="'+ url +'">'+ name +'</a>');
                    }

                    var html = '<div class="mac_drop_box mac_user_box" style="display: none;">';
                    html += '<ul class=\"logged\"><li><a target=\"_blank\" href=\"' + url + '\">' + MAC.TemplateMessage('user_bookmark', 'User Bookmark') + '</a></li><li class=\"logout\"><a class=\"logoutbt\" href=\"javascript:;\" onclick=\"MAC.User.Logout();\" target=\"_self\"><i class=\"user-logout\"></i>' + MAC.TemplateMessage('log_out', 'Log out') + '</a></li></ul>'

                    $('.mac_user').after(html);
                    var h = $('.mac_user').height();
                    var position = $('.mac_user').position();
                    $('.mac_user_box').css({'left': position.left, 'top': (position.top + h)});
                }

            }
            else{

            }

        },
        'CheckLogin':function(){
            if(MAC.User.IsLogin == 0){
                MAC.User.Login();
            }
        },
        'Login': function () {
            return MAC.NativePage('user', MAC.User.IsLogin === 1 ? 'index' : 'login');
        },
        'Logout':function(){
            MAC.Ajax(maccms.path + '/index.php/user/logout','post','json','',function(r){
                MAC.Pop.Msg(100,20,r.msg,1000);
                if(r.code == 1){
                    location.reload();
                }
            });
        },
        'PopedomCallBack':function(trysee,h) {
            window.setTimeout(function(){
                $(window.frames["player_if"].document).find(".MacPlayer").html(h);
            },1000*10*trysee);
        },
        'BuyPopedom': function (o) {
            var $that = $(o), mid = String($that.attr('data-mid') || maccms.mid);
            if (!$that.attr('data-id') || $that.data('purchasePending') || !window.TemplateUI || !maccms.template_urls.user_ajax_buy_popedom || (mid !== '1' && mid !== '2')) return false;
            $that.data('purchasePending', true);
            TemplateUI.confirm(MAC.TemplateMessage('ui.confirm_the_purchase', 'Confirm the purchase')).then(function (accepted) {
                if (!accepted) { $that.removeData('purchasePending'); return; }
                $that.addClass('disabled').attr('aria-disabled', 'true');
                $.ajax({
                    url: maccms.template_urls.user_ajax_buy_popedom,
                    type: 'POST', dataType: 'json', cache: false,
                    data: {id: $that.attr('data-id'), mid: mid, sid: $that.attr('data-sid') || 1, nid: $that.attr('data-nid') || (mid === '2' ? 0 : 1), type: $that.attr('data-type')},
                    success: function (reply) {
                        MAC.Pop.Msg(300, 50, reply.msg, 2000);
                        if (reply.code === 1) window.location.reload();
                    },
                    error: function () { MAC.Pop.Msg(300, 50, MAC.TemplateMessage('ui.connection_failed_try_again', 'Connection failed. Try again.'), 3000); },
                    complete: function () { $that.removeClass('disabled').removeAttr('aria-disabled').removeData('purchasePending'); }
                });
            }, function () { $that.removeData('purchasePending'); });
            return false;
        }
    },
    'Pop': {
        'NoticeId': null,
        'Msg': function (width, height, value, timeout) {
            // Numeric width/height are legacy signature compatibility only.
            if (!window.TemplateUI) return false;
            MAC.Pop.NoticeId = window.TemplateUI.message(String(value == null ? '' : value), {duration: timeout || 7000});
            return MAC.Pop.NoticeId;
        },
        'RemoveMsg': function () { if (window.TemplateUI && MAC.Pop.NoticeId) window.TemplateUI.close(MAC.Pop.NoticeId); MAC.Pop.NoticeId = null; },
        'Remove': function () { MAC.Pop.RemoveMsg(); },
        'Show': function () {
            // Retain both legacy signatures, but never fetch or inject arbitrary AJAX markup.
            var value = typeof arguments[0] === 'number' ? arguments[3] : arguments[1];
            var url;
            try { url = new URL(String(value || ''), location.href); } catch (error) { return false; }
            if (url.origin !== location.origin || !/^https?:$/.test(url.protocol) || url.username || url.password) return false;
            var base;
            try {
                var root = new URL(String(maccms.path || '/'), location.origin + '/');
                if (root.origin !== location.origin || root.username || root.password || root.search || root.hash) return false;
                base = root.pathname.replace(/\/+$/, '') + '/index.php';
            } catch (error) { return false; }
            var route = url.pathname === base ? url.searchParams.get('s') : url.pathname.indexOf(base + '/') === 0 ? url.pathname.slice(base.length + 1) : '';
            route = String(route || '').replace(/^\//, '').replace(/\.html$/, '');
            if (route === 'user/ajax_login') return MAC.NativePage('user', 'login');
            if (route === 'user/ajax_info') return MAC.NativePage('user', 'index');
            if (route === 'gbook/report') {
                var parameters = {}, id = url.searchParams.get('id'), name = url.searchParams.get('name');
                if (id && /^\d+$/.test(id)) parameters.id = id;
                if (name) parameters.name = name.slice(0, 500);
                return MAC.NativePage('gbook', 'index', parameters);
            }
            return false;
        }
    },
    'Pwd': {
        'Check': function (o) {
            var $that = $(o), form = $that.closest('form');
            if (!$that.attr('data-id') || $that.prop('disabled') || !maccms.template_urls.ajax_pwd) return false;
            $that.prop('disabled', true).attr('aria-disabled', 'true');
            $.ajax({url: maccms.template_urls.ajax_pwd, type: 'POST', dataType: 'json',
                data: {id: $that.attr('data-id'), mid: $that.attr('data-mid'), type: $that.attr('data-type'), pwd: form.find('input[name=pwd]').val(), __token__: form.find('input[name=__token__]').val() || ''},
                success: function (reply) { MAC.Pop.Msg(300, 50, reply.msg, 2000); if (reply.code === 1) location.reload(); },
                error: function () { MAC.Pop.Msg(300, 50, MAC.TemplateMessage('ui.connection_failed_try_again', 'Connection failed. Try again.'), 3000); },
                complete: function () { $that.prop('disabled', false).removeAttr('aria-disabled'); }
            });
            return false;
        }
    },
    'Css':function($url){
        $("<link>").attr({ rel: "stylesheet",type: "text/css",href: $url}).appendTo("head");
    },
    'Desktop':function(s){
        location.href= maccms.path + '/index.php/ajax/desktop?name='+encodeURI(s)+'&url=' + encodeURI(location.href);
    },
    'Timming':function(){
        if($('.mac_timming').length==0){
            return;
        }
        var infile = $('.mac_timming').attr("data-file");
        if(infile==undefined || infile == ''){
            infile = 'api.php';
        }
        var t=(new Image());t.src=maccms.path + '/'+infile+'/timming/index?t='+Math.random();
    },
    'Error':function(tab,id,name){

    },
    'AddEm':function(obj,i){
        var oldtext = $(obj).val();
        $(obj).val( oldtext + '[em:' + i +']' );
    },
    'Remaining':function(obj,len,show){
        var count = len - $(obj).val().length;
        if(count < 0){
            count = 0;
            $(obj).val($(obj).val().substr(0,200));
        }
        $(show).text(count);
    },
    'Comment':{
        'Login':0,
        'Verify':0,
        'Init':function(){

            $('body').on('click', '.comment_face_box img', function(e){
                var obj = $(this).parent().parent().parent().find('.comment_content');
                MAC.AddEm(obj,$(this).attr('data-id'));
            });
            $('body').on('click', '.comment_face_panel', function(e){
                // $('.comment_face_box').toggle();
                $(this).parent().find('.comment_face_box').toggle();
            });
            $('body').on('keyup', '.comment_content', function(e){
                var obj = $(this).parent().parent().parent().parent().find('.comment_remaining');
                MAC.Remaining($(this),200,obj)
            });
            $('body').on('focus', '.comment_content', function(e){
                if(MAC.Comment.Login==1 && MAC.User.IsLogin!=1){
                    MAC.User.Login();
                }
            });

            $('body').on('click', '.comment_report', function(e){
                var $that = $(this);
                if($(this).attr("data-id")){
                    MAC.Ajax(maccms.path + '/index.php/comment/report.html?id='+$that.attr("data-id"),'get','json','',function(r){
                        $that.addClass('disabled');
                        MAC.Pop.Msg(100,20,r.msg,1000);
                        if(r.code == 1){
                        }
                    });
                }
            });

            $('body').on('click', '.comment_reply', function(e) {
                var $that = $(this);
                if ($that.attr("data-id")) {
                    var isOpen = $that.attr('data-reply-open') === '1';
                    $('.comment_reply_form').remove();
                    if (isOpen) {
                        $that.attr('data-reply-open', '0').html(MAC.TemplateMessage('ui.reply', 'Reply'));
                        return false;
                    }
                    $('.comment_reply').attr('data-reply-open', '0').html(MAC.TemplateMessage('ui.reply', 'Reply'));
                    var html = $('.comment_form').prop("outerHTML");
            
                    var oo = $(html);
                    oo.addClass('comment_reply_form');
                    oo.find('input[name="comment_pid"]').val($that.attr("data-id"));
            
                    $that.parent().after(oo);
                    $that.attr('data-reply-open', '1').html(MAC.TemplateMessage('ui.cancel_reply', 'Cancel reply'));
                }
            });            

            $('body').on('click', '.comment_submit', function(e){
                var $that = $(this);
                MAC.Comment.Submit($that);
            });

        },
        'Show':function($page){
            if($(".mac_comment").length>0){
                MAC.Ajax(maccms.path + '/index.php/comment/ajax.html?rid='+$('.mac_comment').attr('data-id')+'&mid='+ $('.mac_comment').attr('data-mid') +'&page='+$page,'get','json','',function(r){
                    $(".mac_comment").html(r);
                },function(){
                    $(".mac_comment").html('<a href="javascript:void(0)" onclick="MAC.Comment.Show('+$page+')">' + MAC.TemplateMessage('comments_closed', 'Comments are closed...') + '</a>');
                });
            }
        },
        'Reply':function($o){

        },
        'Submit':function($o){
            var form = $o.parents('form');
            if ($(form).find(".comment_content").val() == '') {
                MAC.Pop.Msg(100, 20, MAC.TemplateMessage('please_enter_comment', 'Please enter your comment!'), 1000);
                return false;
            }
            if ($('.mac_comment').attr('data-mid') == '') {
                MAC.Pop.Msg(100, 20, MAC.TemplateMessage('module_mid_error', 'Module mid error!'), 1000);
                return false;
            }
            if ($('.mac_comment').attr('data-id') == '') {
                MAC.Pop.Msg(100, 20, MAC.TemplateMessage('related_id_error', 'Related id error!'), 1000);
                return false;
            }
            
            MAC.FormPost('comment', $(form).serialize() + '&comment_mid='+ encodeURIComponent($('.mac_comment').attr('data-mid')) + '&comment_rid=' + encodeURIComponent($('.mac_comment').attr('data-id')),function(r){
                MAC.Pop.Msg(100,20,r.msg,1000);
                if(r.code == 1){
                    MAC.Comment.Show(1);
                }
                else{
                    if(MAC.Comment.Verify==1){
                        MAC.Verify.Refresh();
                    }
                }
            });
        }
    }
}

$(function(){
    //a
    // Gallery assets use native image loading; the legacy Image adapter is absent.
    //a
    MAC.Adaptive();
    //a
    MAC.Verify.Init();
    //a
    MAC.PageGo.Init();
    //a
    MAC.User.Init();
    //a
    MAC.Qrcode.Init();
    //a
    MAC.Digg.Init();
    //a
    MAC.Score.Init();
    //a
    MAC.Star.Init();
    //a
    MAC.Hits.Init();
    //a
    MAC.Shorten.Init();
    //a
    MAC.History.Init();
    //a
    MAC.Ulog.Init();
    //a
    MAC.Suggest.Init('.mac_wd',1,'');
    //a
    MAC.Website.Referer();
    //a
    MAC.Timming();
});
