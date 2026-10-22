/* JavCMS example X33: shows what a pop / click script of an ad network does. On the first click on the page
 * (never on buttons, form fields or the ad controls) it opens one local page in a new tab, once per page view.
 * No network requests; the opened page is a local file next to this script (a landing page of the fictional advertiser Ostrelle). Preview only (examples never reach visitors). */
(function () {
  'use strict';
  var src = (document.currentScript && document.currentScript.src) || '';
  if (!src) return;
  var page = new URL('20ac3b01.html', src).href, done = false;
  function onClick(e) {
    if (done || e.button) return;
    var t = e.target;
    if (t && t.closest && t.closest('button,input,select,textarea,label,summary,[role="button"],[data-jcx-act],[data-jcx]')) return;
    done = true;
    document.removeEventListener('click', onClick, true);
    try { window.open(page, '_blank', 'noopener'); } catch (err) { }
  }
  document.addEventListener('click', onClick, true);
})();
