/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Based on MacCMS 10 (Apache-2.0) by magicblack, changed by JavCMS.
 * JavCMS changes: Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors,
 * JavCMS Community License 1.0. The MacCMS parts stay under the Apache License 2.0.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
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

            'mobile': !!ua.match(/AppleWebKit.*Mobile.*/), // Mobile WebKit user-agent pattern.

            'ios': !!ua.match(/\(i[^;]+;( U;)? CPU.+Mac OS X/), // iOS-style user-agent pattern.

            'android': ua.indexOf('Android') > -1 || ua.indexOf('Linux') > -1, // Android or Linux user-agent marker.

            'iPhone': ua.indexOf('iPhone') > -1 || ua.indexOf('Mac') > -1, // iPhone or Mac user-agent marker.

            'iPad': ua.indexOf('iPad') > -1, // iPad user-agent marker.

            'trident': ua.indexOf('Trident') > -1, // Trident engine marker.

            'presto': ua.indexOf('Presto') > -1, // Presto engine marker.

            'webKit': ua.indexOf('AppleWebKit') > -1, // WebKit engine marker.

            'gecko': ua.indexOf('Gecko') > -1 && ua.indexOf('KHTML') == -1, // Gecko marker, excluding KHTML.

            'weixin': ua.indexOf('MicroMessenger') > -1 // WeChat marker; legacy alternative: ua.match(/MicroMessenger/i) == \"micromessenger\",

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

            window.location.assign(window.location.origin + '/');

        }

    },

    'Adaptive':function(){

        if(maccms.mob_status=='1'&& maccms.url != maccms.wapurl){

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

    'Qrcode':function(){

        window.MacLocalQrCode && window.MacLocalQrCode.render('.mac_qrcode', MAC.Url, 150);

    },

    'Image':{

        'Lazyload':{

            'Show': function(){

                try { $("img.lazy").lazyload(); }catch(e){};

            },

            'Box': function($id){

                $("img.lazy").lazyload({

                    container: $("#"+$id)

                });

            }

        }

    },

    'Verify': {

        'Init': function(){

            MAC.Verify.Focus();

            MAC.Verify.Click();
            $('img.mac_verify_img,img.fed-user-code,#verify_img').attr({role:'button',tabindex:'0'});
            $('body').off('keydown.macVerify', 'img.mac_verify_img,img.fed-user-code,#verify_img').on('keydown.macVerify', 'img.mac_verify_img,img.fed-user-code,#verify_img', function(event){
                if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); $(this).trigger('click'); }
            });

        },

        'Focus': function(){// Show a verification image when the field receives focus.

            $('body').off('focus.macVerify', '.mac_verify').on('focus.macVerify', '.mac_verify', function(){

                var field = $(this);
                if (!field.siblings('img.mac_verify_img').length) field.after(MAC.Verify.Show());
                field.removeClass('mac_verify');

            });

        },

        'Click': function(){// Refresh the verification image when clicked.

            $('body').off('click.macVerify', 'img.mac_verify_img').on('click.macVerify', 'img.mac_verify_img', function(){
                if (this.hasAttribute('onclick')) return;

                $(this).attr('src', maccms.path +'/index.php/verify/index.html?_='+Date.now());

            });

        },

        'Refresh':function(){

            $('.mac_verify_img').attr('src', maccms.path +'/index.php/verify/index.html?_='+Date.now());

        },

        'Show':function(){

            var label = MAC.TemplateMessage('not_to_see_change', 'Refresh verification code');
            return $('<img>').addClass('mac_verify_img').attr({role:'button',tabindex:'0'}).attr('src', maccms.path + '/index.php/verify/index.html?').attr('title', label).attr('alt', label).prop('outerHTML');

        }

    },

    'PageGo':{

        'Init':function() {

            $('.mac_page_go').click(function () {

                var that =$(this);

                var url = that.attr('data-url');

                var total = that.attr('data-total');

                var sp = that.attr('data-sp');

                var page= $('#page').val();

                if(page>0&&(page<=total)){

                    url=url.replace(sp + 'PAGELINK',page).replace('PAGELINK',page);

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

            $.ajax({

                type: 'get',

                url: maccms.path + '/index.php/ajax/hits?mid='+$that.attr("data-mid")+'&id='+$that.attr("data-id")+'&type=update',

                timeout: 5000,

                dataType: 'json',

                error: function () {

                },

                success: function (json) {

                    if (json.code == 1) {

                        $(".mac_hits").each(function(i){

                            $type = $(".mac_hits").eq(i).attr('data-type');

                            if($type != 'insert'){

                                $(this).text(Number(json.data[$type]) || 0);

                            }

                        });

                    }

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

            $.ajax({

                type: 'post',

                url: maccms.path+'/index.php/ajax/score?mid='+ $('.mac_score').attr('data-mid') +'&id=' +$('.mac_score').attr('data-id'),

                timeout: 5000,

                error: function(){

                    $(".mac_score").text(MAC.TemplateMessage('request_failed', 'Unable to complete the request. Please try again.'));

                },

                success: function($r){

                    MAC.Score.View($r);

                }

            });

        },

        'Submit':function(){

            var $s = $('.mac_score').find("input[name='score']").val();

            $.ajax({

                type: 'post',

                url: maccms.path+'/index.php/ajax/score?mid='+$('.mac_score').attr('data-mid')+'&id='+$('.mac_score').attr('data-id') + '&score='+ $s,

                timeout: 5000,

                error: function(){

                },

                success: function($r){

                    MAC.Pop.Msg(100,20,$r.msg,1000);

                    if($r.code==1){

                        MAC.Score.View($r);

                    }

                }

            });

        },

        'View':function($r){

            $(".rating"+Math.floor($r.data.score)).attr('checked',true);

            $(".score_num").text( $r.data.score_num );

            $(".score_all").text( $r.data.score_all );

            $(".score_pjf").text( $r.data.score );

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

                    $.ajax({

                        type: 'get',

                        url: maccms.path+'/index.php/ajax/score?mid='+$('.mac_star').attr('data-mid')+'&id='+$('.mac_star').attr('data-id')+'&score='+(score*2),

                        timeout: 5000,

                        dataType:'json',

                        error: function(){

                            $('.star_box').attr('title', MAC.TemplateMessage('request_failed', 'Unable to complete the request. Please try again.'));

                        },

                        success: function(json){

                            if(json.status == 1){

                                $('.star_tips').html(json.data.score);

                            }else{

                                $('.star_box').attr('title', json.msg);

                            }

                        }

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

                    $.ajax({

                        url: maccms.path + '/index.php/ajax/digg.html?mid='+$that.attr("data-mid")+'&id='+$that.attr("data-id")+'&type='+$that.attr("data-type"),

                        cache: false,

                        dataType: 'json',

                        success: function($r){

                            $that.addClass('disabled');

                            if($r.code == 1){

                                if($that.attr("data-type")=='up'){

                                    $that.find('.digg_num').text(Number($r.data.up) || 0);

                                }

                                else{

                                    $that.find('.digg_num').text(Number($r.data.down) || 0);

                                }

                            }

                            else{

                                $that.attr('title', $r.msg);

                            }

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

        'Show': function (page) {
            var target = new URL(maccms.template_urls.gbook_index, location.href);
            target.searchParams.set('page', String(page));
            location.assign(target.href);
        },

        'Submit':function(){

            if($(".gbook_content").val() == ''){

                MAC.Pop.Msg(300, 50, MAC.TemplateMessage('ui.enter_message', 'Enter a message'), 2000);

                return false;

            }

            MAC.FormPost('gbook', $('.gbook_form').serialize(), function($r){

                	MAC.Pop.Msg(300, 50, $r.msg, 2000);

                    if($r.code == 1){

                        location.reload();

                    }

                    else{

                        if(MAC.Gbook.Verify==1){

                            MAC.Verify.Refresh();

                        }

                    }
            });

        },

        'Report':function(name){

            MAC.Pop.Show('#modal-seport',maccms.path+'/index.php/gbook/report?name=' + encodeURIComponent(name),function($r){

                $('body').on('click', '#gbook_submit', function(e){

	            	MAC.Gbook.Submit();

		        });

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

                    width: 175, scrollHeight: 300, minChars: 1, matchSubset: 1,

                    cacheLength: 10, multiple: false, matchContains: true, autoFill: false,

                    dataType: "json",

                    parse: function ($r) {

                        if ($r.code == 1) {

                            var parsed = [];

                            $.each($r['list'], function (index, row) {

                                row.url = $r.url;

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

    'Ulog':{

        'Init':function(){

            MAC.Ulog.Set();

            MAC.Ulog.Click();

        },

        'Get':function(){

            $.ajax({

                url: maccms.template_urls.user_ajax_ulog,
                data: {ac: 'list'},

                type:'GET',

                cache: false,

                dataType: 'json',

                success: function($r){

                    if($r.code == 1){

                        MAC.Ulog.Rows = Array.isArray($r.list) ? $r.list : [];

                    }else{

                    }

                }

            });

        },

        'Set':function(){

            if($(".mac_ulog_set").attr('data-mid')){

                var $that = $(".mac_ulog_set");

                $.ajax({url: maccms.template_urls.user_ajax_ulog, type: 'POST', data: {ac: 'set', mid: $that.attr('data-mid'), id: $that.attr('data-id'), sid: $that.attr('data-sid'), nid: $that.attr('data-nid'), type: $that.attr('data-type')}});

            }

        },

        'Click':function(){

            $('body').on('click', 'a.mac_ulog', function(e){

                // Prompt users to log in before recording this action.

                if(MAC.User.IsLogin == 0){

                    MAC.User.Login();

                    return;

                }

                var $that = $(this);

                if($that.attr("data-id")){

                    $.ajax({

                        url: maccms.template_urls.user_ajax_ulog,
                        type: 'POST', data: {ac: 'set', mid: $that.attr('data-mid'), id: $that.attr('data-id'), type: $that.attr('data-type')},

                        cache: false,

                        dataType: 'json',

                        success: function($r){

                            MAC.Pop.Msg(300, 50, $r.msg, 2000);

                            if($r.code == 1){

                                $that.addClass('disabled');

                            }else{

                                $that.attr('title', $r.msg);

                            }

                        }

                    });

                }

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

            }

            if(MAC.Cookie.Get('user_id') !=undefined && MAC.Cookie.Get('user_id')!=''){

                var url = maccms.path + '/index.php/user';

                MAC.User.UserId = MAC.Cookie.Get('user_id');

                MAC.User.UserName = MAC.Cookie.Get('user_name');

                MAC.User.GroupId = MAC.Cookie.Get('group_id');

                MAC.User.GroupName = MAC.Cookie.Get('group_name');

                MAC.User.Portrait = MAC.Cookie.Get('user_portrait');

                MAC.User.IsLogin = 1;

                $('.mac_user').removeClass('mac_user').attr('href',url);

                $('.user_name').show().find('.name').text(MAC.User.UserName);

                $('.user_url').show().attr('href',url);

                $('.user_img').show().attr('src',MAC.User.Portrait);

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

            $.ajax({

                type: 'post',

                url: maccms.path + '/index.php/user/logout',

                success:function($r){

                    MAC.Pop.Msg(100,20,$r.msg,1000);

                    if($r.code == 1){

                        location.reload();

                    }

                }

            });

        },

        'PopedomCallBack':function(trysee,h) {

            window.setTimeout(function(){

                $(window.frames["player_if"].document).find(".MacPlayer").html(h);

            },1000*60*trysee);

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

    'AdsWrap':function(w,h,n){

        return ''; /* legacy ad placeholder disabled */

    },

    'Css':function($url){

        $("<link>").attr({ rel: "stylesheet",type: "text/css",href: $url}).appendTo("head");

    },

    'Js':function($url){

        $.getScript($url, function(response, status) {

        });

    },

    'Desktop':function(s){

        location.href= maccms.path + '/index.php/ajax/desktop?name='+encodeURI(s)+'&url=' + encodeURI(location.href);

    },

    'Timming':function(){

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

                // $(\'.comment_face_box\').toggle();

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

                    $.ajax({

                        url: maccms.template_urls.comment_report,
                        type: 'POST', data: {id: $that.attr('data-id')},

                        cache: false,

                        dataType: 'json',

                        success: function($r){

                            $that.addClass('disabled');

                            MAC.Pop.Msg(100,20,$r.msg,1000);

                            if($r.code == 1){

                            }

                        }

                    });

                }

            });

            $('body').on('click', '.comment_reply', function(e){

                var $that = $(this);

                if($that.attr("data-id")){

                    var open = $that.data('replyOpen') === true;
                    $('.comment_reply_form').remove();
                    $('.comment_reply').removeData('replyOpen').text(MAC.TemplateMessage('ui.reply', 'Reply'));
                    if (open) return false;
                    var replyForm = $($('.comment_form').first().prop('outerHTML'));
                    replyForm.addClass('comment_reply_form');
                    replyForm.find('input[name=comment_pid]').val($that.attr('data-id'));
                    $that.parent().after(replyForm);
                    $that.data('replyOpen', true).text(MAC.TemplateMessage('ui.cancel_reply', 'Cancel reply'));

                }

            });

            $('body').on('click', '.comment_submit', function(e){

                var $that = $(this);

                MAC.Comment.Submit($that);

            });

        },

        'Show':function($page){

            if($(".mac_comment").length>0){

                $.ajax({

                    type: 'get',

                    url: maccms.template_urls.comment_ajax,
                    data: {rid: $('.mac_comment').attr('data-id'), mid: $('.mac_comment').attr('data-mid'), page: $page},

                    timeout: 5000,

                    error: function(){

                        $(".mac_comment").text(MAC.TemplateMessage('request_failed', 'Unable to complete the request. Please try again.'));

                    },

                    success:function($r){

                        $(".mac_comment").html($r);

                    }

                });

            }

        },

        'Reply':function($o){

        },

        'Submit':function($o){

            var form = $o.parents('form');

            if($(form).find(".comment_content").val() == ''){

                MAC.Pop.Msg(100,20,MAC.TemplateMessage('ui.enter_comment', 'Enter a comment'),1000);

                return false;

            }

            if($('.mac_comment').attr('data-mid') == ''){

                MAC.Pop.Msg(100,20,MAC.TemplateMessage('parameter_error', 'Parameter error'),1000);

                return false;

            }

            if($('.mac_comment').attr('data-id') == ''){

                MAC.Pop.Msg(100,20,MAC.TemplateMessage('parameter_error', 'Parameter error'),1000);

                return false;

            }

            MAC.FormPost('comment', $(form).serialize() + '&comment_mid='+ $('.mac_comment').attr('data-mid') + '&comment_rid=' + $('.mac_comment').attr('data-id'), function($r){

                    MAC.Pop.Msg(100,20,$r.msg,1000);

                    if($r.code == 1){

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

    // Apply mobile-site redirects when configured.

    MAC.Adaptive();

    // Initialize verification-image handlers.

    MAC.Verify.Init();

    // Initialize page-number navigation.

    MAC.PageGo.Init();

    // Initialize user UI from cookies.

    MAC.User.Init();

    // Initialize vote buttons.

    MAC.Digg.Init();

    // Load ratings and initialize rating submission.

    MAC.Score.Init();

    // Initialize star-rating controls.

    MAC.Star.Init();

    // Update and display content hit counts.

    MAC.Hits.Init();

    // Initialize user activity logging.

    MAC.Ulog.Init();

    // Initialize video search suggestions.

    MAC.Suggest.Init('.mac_wd',1,'');

    // Run the timing hook.

    MAC.Timming();

});
