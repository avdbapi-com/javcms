/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
/* Original layouts with native controls, using the secured common runtime. */
(function () {
 'use strict';
 document.addEventListener('DOMContentLoaded', function () {
  var menu=document.querySelector('.classic-menu-toggle'), wrapped=document.querySelector('.wrapped'), search=document.querySelector('.header .btn-search'), header=document.querySelector('.header');
  function closeMenu(){if(menu)menu.setAttribute('aria-expanded','false');if(wrapped)wrapped.classList.remove('open');document.body.classList.remove('no-scroll');document.documentElement.classList.remove('no-scroll');}
  if(menu&&wrapped)menu.addEventListener('click',function(){var open=menu.getAttribute('aria-expanded')!=='true';menu.setAttribute('aria-expanded',String(open));wrapped.classList.toggle('open',open);});
  if(search&&header)search.addEventListener('click',function(){var open=search.getAttribute('aria-expanded')!=='true';search.setAttribute('aria-expanded',String(open));header.classList.toggle('search-active',open);if(open){var input=header.querySelector('input[name="wd"]');if(input)input.focus();}});
  document.addEventListener('keydown',function(e){if(e.key==='Escape'){closeMenu();if(header)header.classList.remove('search-active');if(search)search.setAttribute('aria-expanded','false');document.querySelectorAll('.stui_header__user .dropdown').forEach(function(x){var opener=x.parentElement.querySelector('a'),restore=x.contains(document.activeElement);x.style.display='none';if(opener){opener.setAttribute('aria-expanded','false');if(restore)opener.focus();}});}});
  document.querySelectorAll('.stui_header__user>li>a').forEach(function(a){var dropdown=a.parentElement.querySelector('.dropdown');if(!dropdown)return;a.setAttribute('aria-expanded','false');a.addEventListener('click',function(e){e.preventDefault();var open=a.getAttribute('aria-expanded')!=='true';a.setAttribute('aria-expanded',String(open));dropdown.style.display=open?'block':'none';});});
  document.querySelectorAll('details.filter').forEach(function(f){f.addEventListener('toggle',function(){f.classList.toggle('show',f.open);});});
  document.querySelectorAll('.header .logo img,.footer .logo img').forEach(function(img){function fallback(){if(img.dataset.classicFallback)return;img.dataset.classicFallback='1';img.src=(window.maccms&&window.maccms.path||'')+'/static/template-ui/brand-javcms.svg';}img.addEventListener('error',fallback);if(img.complete&&!img.naturalWidth)fallback();});
  if(menu)window.addEventListener('resize',function(){if(innerWidth>=1300)closeMenu();});
 });
})();
