/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
// Builds static/jcx/{jcx.js,jcx-l.js,jcx-m.js,jcx-p.js,jcx.css,jcx-v.js,jcx-a.js} from the readable sources in this folder and
// checks the size budgets of ADS-BRIEF 11.3 (gzip -9). jcx-m.js (rarer formats loaded on demand: U13 widgets, U08 / U09
// modal) was split out of jcx.js and jcx-l.js in repair round 2 so both keep their budgets; it has its own (2 KB).
// Output is ES2020 (arrow functions): the runtime already needs ES2020 browsers
// (dynamic import() in jcx-v.js, IntersectionObserver, ResizeObserver, <dialog>), so nothing older loses anything. Needs terser 5 (build tool only, not shipped):
//   npm install --no-save terser@5.36.0   (in any folder; pass that folder's node_modules/terser as TERSER)
//   node deployment/jcx-src/build.js
const fs = require('fs'), path = require('path'), zlib = require('zlib');
const { minify } = require(process.env.TERSER || 'terser');
const out = path.join(__dirname, '..', '..', 'static', 'jcx');
// jcx-a.js (the VAST part of the video ads, loaded by jcx-v.js only for a VAST item; since fix round 3 also the HLS loader and
// the tag test, loaded for an m3u8 ad too) was split out of jcx-v.js in the video
// fix round (2026-10-16) so jcx-v.js keeps its 7 KB with the fallback chain and the budgets; it has its own (1.5 KB).
const budget = { 'jcx.js': 5120, 'jcx-l.js': 3072, 'jcx-m.js': 2048, 'jcx-p.js': 6144, 'jcx.css': 2560, 'jcx-v.js': 7168, 'jcx-a.js': 1536 };
// a descendant combinator before a pseudo-class (".jcx-free :is(...)") must keep its space (R3r2-04 / R1r2-04)
const desc = s => (s.match(/[\w\])] :[a-z]/g) || []).length;
(async () => {
  let fail = 0;
  for (const f of Object.keys(budget)) {
    const code = fs.readFileSync(path.join(__dirname, f), 'utf8');
    let min;
    if (f.endsWith('.js')) {
      min = (await minify(code, { ecma: 2020, compress: { passes: 3, unsafe: true, unsafe_arrows: true }, mangle: { toplevel: true }, toplevel: true, format: { wrap_func_args: false } })).code;
    } else {
      const src = code.replace(/\/\*[^*]*\*+([^/*][^*]*\*+)*\//g, '').replace(/\s+/g, ' ');
      // never strip the space before ':' (a selector "a :is(b)" is not "a:is(b)"); only "prop: value" loses it
      min = src.replace(/ ?([{};,>]) ?/g, '$1').replace(/: /g, ':').replace(/;}/g, '}').trim();
      if (desc(min) !== desc(src)) { console.log('FAIL ' + f + ': a descendant combinator before a pseudo-class was lost'); fail = 1; }
    }
    fs.writeFileSync(path.join(out, f), min + '\n');
    const gz = zlib.gzipSync(Buffer.from(min + '\n'), { level: 9 }).length;
    if (gz > budget[f]) fail = 1;
    console.log((gz <= budget[f] ? 'OK   ' : 'OVER ') + f.padEnd(9) + ' gzip ' + gz + ' / ' + budget[f]);
  }
  process.exit(fail);
})();
