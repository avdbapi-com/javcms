/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
/* Native MacCMS portrait upload: multipart file field and current form token. */
(function(){'use strict';
function init(){var form=document.getElementById('gallery-avatar-form');if(!form||!window.jQuery)return;var messages=window.galleryAvatarMessages||{};var status=form.querySelector('.gallery-avatar-status');var input=form.querySelector('input[name="file"]');var button=form.querySelector('button[type="submit"]');var busy=false;
form.addEventListener('submit',function(event){event.preventDefault();if(busy)return;if(!input.files.length){status.textContent=messages.select_avatar_file;return;}var endpoint;try{endpoint=new URL(form.action,location.href);if(endpoint.origin!==location.origin||!/^https?:$/.test(endpoint.protocol))throw new Error('Invalid endpoint');}catch(error){status.textContent=messages.avatar_upload_failed;return;}busy=true;button.disabled=true;form.setAttribute('aria-busy','true');status.textContent='';
jQuery.ajax({url:endpoint.href,type:'POST',data:new FormData(form),processData:false,contentType:false,dataType:'json',success:function(response){if(response&&response.__token__)document.querySelectorAll('input[name="__token__"]').forEach(function(token){token.value=response.__token__;});if(!response||Number(response.code)!==1){status.textContent=response&&typeof response.msg==='string'?response.msg:messages.avatar_upload_failed;return;}try{var imageUrl=new URL(response.file,location.href);if(typeof response.file!=='string'||imageUrl.origin!==location.origin||!/^https?:$/.test(imageUrl.protocol))throw new Error('Invalid image URL');var avatar=document.querySelector('.gallery-account .fed-user-image img');if(avatar)avatar.src=imageUrl.href;status.textContent=messages.avatar_updated;}catch(error){status.textContent=messages.avatar_upload_failed;}},error:function(){status.textContent=messages.avatar_upload_failed;},complete:function(){busy=false;button.disabled=false;form.removeAttribute('aria-busy');}});
});}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
