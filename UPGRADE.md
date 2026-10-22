# Upgrade from the previous release

This guide moves a site from JavCMS **1.1.0** (2026-10-15) to **1.2.0**. Your videos, members, settings and uploads
stay. Nothing in your existing data is rewritten.

Plan about 30 minutes, plus the time of two background steps on big sites (step 8). Do it at a quiet time of day.

## What changes in short

- **New:** xvidapi as a second video source, the **Import videos** page with size estimates, the
  **Speed & protection** page, a search index, a page cache and fair limits per visitor network,
  the command-line importer `tools/import.php`, and the tools `tools/upgrade.php`, `tools/search-index.php`
  and `tools/db-tune.php`.
- **Protection is on after the upgrade, with high values:** turning visitors away when busy, per-visitor request
  limits (300 searches or filters and 2,400 other pages per minute per visitor network) and the query time limit.
  Checked search engines (Googlebot, Bingbot, YandexBot, DuckDuckBot, Applebot) are never blocked. Under attack mode
  is off. Nothing to set; to change a value see [docs/SECURITY.md](docs/SECURITY.md), section 10.
- **Themes:** every theme changes (category chips, the International block on the home page, quality badges,
  small-picture handling). If you edited theme files, read "Theme files changed in 1.2.0" below **before** you copy.
- **JAVCMS Classic (`template/javcms`) is not in this release.** It loaded a stylesheet and an icon font of a
  third-party theme and a stylesheet of the 8X theme pack whose authors and licenses are not known. New installs start
  with **JAVCMS Modern** (`javcms-modern`). If your site uses JAVCMS Classic (Site & theme › Themes shows the theme in
  use), switch to JAVCMS Modern or another theme (**Use this theme**). Copying the new files does not delete your
  `template/javcms/` folder and the theme list still offers it, but this release does not update it; after you have
  switched you can delete `template/javcms/` from your server.
- **Database:** new tables and a few new columns are added by themselves; the new list and sort indexes, the word
  indexes (FULLTEXT) for the title, actor, tag, category and director filters, and the search index are added in a
  separate step that you start (step 8). No existing data is changed.
- **License:** from 1.2.0 JavCMS is under the JavCMS Community License 1.0 (share it, don't sell it); see `LICENSE`
  and the License section of [README.md](README.md). Nothing to do for you.
- **Admin styles:** the admin loads `static/css/admin_base.css` instead of `static/css/dashlite.css`; some headings,
  buttons and badges in the admin look a little different. After the upgrade you can delete `static/css/dashlite.css`
  from your server; nothing uses it any more.
- **Editor emoticons and unused files:** the UMeditor editor has no emoticon button any more, and this release does
  not have its emoticon images (the folders `babycat`, `bobo`, `face`, `jx2`, `ldw`, `tsj` and `youa` in
  `static/editor/umeditor/dialogs/emotion/images/`), the unused Pear Admin Layui files (`static/pear/`,
  `static/admin/css/`, `static/admin/images/`; keep `static/admin/icons/`), the folder `static/images/other/`, the
  images `foot-bg.png`, `foot-bg1.png` and `home-bg1.png` in `static/images/pro/`, and the images in
  `static/images/pro-home/` other than `home-logo.png` and `home-logo-b.png`. You can delete them from your server;
  keep the emoticon folders if texts on your site show emoticons that were inserted with the editor.
- **More editor emoticons:** the KindEditor and UEditor editors have no emoticon button any more, and this release does
  not have `static/editor/kindeditor/plugins/emoticons/images/`, the files `bface.gif`, `cface.gif`, `fface.gif`,
  `jxface2.gif`, `tface.gif`, `wface.gif` and `yface.gif` in `static/ueditor/dialogs/emotion/images/` and in
  `static/editor/umeditor/dialogs/emotion/images/`, `static/layui/images/face/` and `static/images/face/`. You can
  delete them from your server; keep them if texts on your site show emoticons that were inserted with an editor.
- **Flash player:** you can delete `static/player/play.swf` and `static/player/flv.js` (Flash; browsers no longer run
  it, and no player of the package uses them).
- **Captcha font:** the captcha now uses `vendor/topthink/think-captcha/assets/ttfs/DroidSansMono.ttf`. After you copy
  the new files, **delete** `1.ttf` to `6.ttf` in that folder (third-party freeware fonts; while they are there the
  captcha keeps picking one of them at random) and the folder `vendor/topthink/think-captcha/assets/bgs/` (unused).
- **Player, jav, neon and editor images:** `static/player/artplayer/ploading.gif`, `indicator.svg`,
  `template/jav/statics/img/play_bg.webp`, `template/jav/statics/img/play.png`, 14 icons in
  `template/neon/assets/img/icons/` and the file-type icons in `static/ueditor/dialogs/attachment/fileTypeImages/` are
  new images; copying the new files replaces the old ones. You can delete `state.svg`, `subtitle.svg`, `1s_blank.mp4`
  and `javcms-online.gif` in `static/player/artplayer/`, and in `template/neon/assets/img/icons/` the unused files
  `avatar-ring.png`, `down.png`, `fav.png`, `fav-on.png`, `history.png`, `notice.png`, `refresh.png`, `share.png`,
  `up.png`, the ten `tab-*.png` files and the nine `u-*.png` files.
- **Unused files without a license:** you can delete `static/layui/lay/modules/dropdown.js`, `dropdown.css` and
  `static/js/jquery.lang.js`; nothing uses them.
- Your settings file, your database settings, your uploads and your install lock stay as they are.

Full list of changes: [CHANGELOG.md](CHANGELOG.md)

## Step 1. Back up everything

Do not skip this. If something goes wrong, the backup brings your site back.

**Database:**

1. Sign in to your admin.
2. Open Admins & backup › Backup & restore.
3. Click **Back up everything**. Keep the page open until it is done.
4. Click **Download** and save the file on your computer.

On a big site use phpMyAdmin (select your database, **Export**, **Go**) or the backup tool of your hosting panel.

**Files:**

1. In your hosting panel, download your whole site folder as a zip
   (File Manager › select all › Compress › Download).
2. If that is too big, download at least these:
   - `application/database.php`
   - `application/route.php`
   - the folder `application/extra/`
   - the folder `application/data/`
   - the folder `upload/`
   - `.htaccess` (Apache) or `web.config` (IIS)
   - any theme folder in `template/` that you changed yourself
   - `robots.txt`, `ads.txt` and other files you added to the site root yourself

## Step 2. Write down what you changed

Check these before you copy new files:

- **Did you rename `javcms.php`?** Write down your admin file name.
- **Did you edit `.htaccess` or `web.config`?** Keep a copy of your changes.
- **Did you edit files inside a theme folder?** The upgrade replaces theme files. Copy your changed files somewhere safe
  and compare them with the list "Theme files changed in 1.2.0" below.

## Step 3. Optional: switch the site off while you work

Site & theme › Site identity › **Site on or off** › switch the site off › Save.
Visitors see a short "closed" message. You switch it on again at the end.

## Step 4. Pause your imports

If automatic imports are on, open **Import › Import videos** and click **Pause automatic imports**,
and pause a running import. You resume them in step 9.

## Step 5. Prepare the new files on your computer

1. Extract the new JavCMS zip on your computer.
2. **Delete these from the extracted files**, so they do not replace yours:
   - `application/database.php` (your database settings)
   - `application/route.php` (your page address settings)
   - every file in the folder `application/extra/` (your site settings) **except `version.php`**. Keep the new
     `application/extra/version.php`: it holds the version number (1.2.0) and the license name that the admin shows,
     and the admin uses the version number to load its new styles. It holds no setting of yours.
3. Keep everything else. The new `application/data/`, `upload/` and `runtime/` folders contain
   only new example files and empty placeholders. They do not replace your data.
   The release has no `install.lock`, so your own lock file stays in place.
4. If you deleted `install.php` from your site for safety, you can delete it from the new files too
   (see [docs/SECURITY.md](docs/SECURITY.md)).

## Step 6. Upload and replace

1. Upload the remaining new files into your site folder.
2. When asked, choose **replace / overwrite** existing files.
3. If you renamed `javcms.php`:
   - delete your old admin file,
   - rename the new `javcms.php` to your secret name,
   - in the new `.htaccess` (or `web.config`), replace the word `javcms` with your name
     in the two lines that list `index|api|javcms|install`.
     See [docs/SECURITY.md](docs/SECURITY.md).
4. If you had your own changes in `.htaccess` or `web.config`, add them again to the **new** file.
   The new `.htaccess` and the Nginx, IIS and OpenLiteSpeed examples in `deployment/` have longer browser caching for
   pictures, scripts and styles. Do not go back to the old file.
5. Upload your changed theme files again only if you still need them, and only after comparing them with the new
   versions (see the list below).

## Step 7. Clear the cache and open the admin

1. Sign in to the admin. Use your admin address.
2. Click **Clear cache** in the top bar.
3. Reload the admin page in your browser with Ctrl+F5 (Cmd+Shift+R on a Mac),
   so your browser loads the new admin files.
4. If your site is behind a CDN (for example Cloudflare), purge its cache once
   ("Purge Everything"), so it stops serving copies of the old files.
5. If your host uses PHP OPcache (most do), restart PHP or reset OPcache once in
   your hosting panel, so PHP stops using its saved copies of the old program
   files. Clear cache in the admin does not reset OPcache.

The first admin visit adds the new tables by itself, in a few seconds: the page counts, the sitemap blocks and the view
counter buffer, the search index tables, and the new importer tables and columns (the source of each import job, the
state of each imported video). Each step first checks what is already there, so it never runs twice.

## Step 8. Finish the upgrade (two steps you start)

Two steps can take minutes on a big site, so JavCMS never starts them from a visitor's page view.
Open **Admins & backup › Speed & protection**:

1. **Counts and list indexes** says "The upgrade is not finished: N list indexes are missing".
   Click **Finish upgrade (about N min)**. It adds the missing indexes to the video table: the list and sort
   indexes, and the word indexes (FULLTEXT) `ft_vod_name`, `ft_vod_actor`, `ft_vod_tag`, `ft_vod_class` and
   `ft_vod_director` that make the actor, tag, category and director filters fast (a fresh 1.2.0 install has them
   from the start). Indexes that are already there are skipped. Seconds on a VPS with 500,000 videos, a few minutes
   on shared hosting, longer with millions of videos. Your site stays online; while a word index is added, saving
   videos waits a moment, and until it is done, lists and filters use the old way. Clicking twice is safe: a second
   click waits for the first. When the indexes are complete, it also builds the sitemap files for up to 30 seconds and
   says how many it built ("Sitemap files: ..."); housekeeping builds the rest. The files are made from the sitemap
   blocks, which housekeeping (or `php tools/upgrade.php`) builds within minutes after the upgrade: before they exist
   the button says "Sitemap files: built by housekeeping after the sitemap blocks", which is normal. When the site
   already has a search index whose word statistics are due (an index built by an earlier 1.2.0 build, more than 5 %
   new videos since the last refresh, or older than a week), it refreshes them (under a minute with 7 million videos)
   and rebuilds the term map of the search index when that fits into the same minute ("Search statistics refreshed
   (N terms). Term map rebuilt."); a bigger term map is rebuilt by housekeeping with cron or on PHP-FPM / LiteSpeed
   hosting ("Housekeeping rebuilds the term map"), or by `php tools/search-index.php --map`. Housekeeping raises PHP's
   memory limit for it by itself (it needs up to about 256 MB); where the host keeps it lower, Speed & protection says
   so: then run `php tools/search-index.php --map` once. When this step cannot run,
   the reply says why: then run `php tools/search-index.php --terms` and then `php tools/search-index.php --map` once.
   Then it downloads the search engines' current IP lists ("Search engine IP lists refreshed: 4 of 4."); when a download
   fails, the list shipped with JavCMS stays in use until housekeeping gets the current one.
   With a terminal you can run the same step:

   ```
   php tools/upgrade.php
   ```

   It also checks file locks and proxies, prints the hosting row for your catalog size (see
   [docs/HOSTING.md](docs/HOSTING.md)), and does nothing when run again. It does more than the button: it also builds
   the search index (item 2 below) and the first deep-page maps, which takes most of its time, and it refreshes due word
   statistics together with the whole term map ("5b. Search statistics: refreshed (N terms ...)"; with 7 million videos
   under a minute for the statistics and about 3.5-4 minutes for the term map). On a copy of a site with
   525,000 AVDB videos in 16 languages the whole command took about 16-17 minutes, 13 of them for the search index;
   your site stays online meanwhile. `--no-search-backfill` leaves the search index to the button or to
   `php tools/search-index.php --backfill`, `--no-deep-maps` leaves the maps to housekeeping.
   Each run also builds missing sitemap files
   for up to 2 minutes (`--sitemap-budget=0` leaves them to housekeeping; add `--scheme=https` or `--scheme=http` when your
   preferred canonical protocol is "auto" and nobody has opened your sitemap yet). It also writes the rows lists of the
   sitemap blocks built before 1.2.0 for up to 3 minutes (`runtime/scale/sitemap_rows/`, about 100 MB with 7 million
   videos: a never-built sitemap file is answered from them; `--sitemap-rows-budget=0` leaves them to housekeeping,
   `--sitemap-rows-budget=N` gives N seconds). `--no-network` skips the download of the search engines' IP lists (the
   package ships them; housekeeping refreshes them daily).
2. **Search index** says "Not built yet". Click **Build search index** and keep the page open
   (about 8 minutes for 500,000 videos with all languages). The page builds it in steps at every catalog size; with
   more than a million videos and cron running, it shows the terminal command instead (faster):

   ```
   php tools/search-index.php --backfill
   ```

   Until it is ready, search works the old way.

The page counts and sitemap blocks fill themselves within minutes (housekeeping). On a site with more than a million
videos, add the cron line that Speed & protection shows ("Set up cron").

**Deep pages.** 1.2.0 has no page limits: every list, tag, actor and search result is reachable, down to the oldest
video (actor pages and the tag pages that the term map cannot prove exact number at most their first 10,000 videos and
reach every older one through "Older"; see [docs/MAINTENANCE.md](docs/MAINTENANCE.md), "Search"). On a big site this
needs a page map, rank files and a term map, which maintenance builds by itself after the upgrade
(`php tools/upgrade.php` builds the first ones at once). Until they are ready, the line **Deep pages** on Speed &
protection says "Deep pages being prepared (N %)" and very deep pages may answer slower or ask visitors to retry; pages
near the start work as before.

## Step 9. Check your imports

- Old AVDB jobs and automatic imports work as before; they are now marked as source AVDB.
  The first refresh of an old video rewrites it once; after that, unchanged videos are skipped quickly.
- Resume what you paused in step 4.
- The first time you open **Import › Import videos**, JavCMS asks for your database limit
  ([docs/HOSTING.md](docs/HOSTING.md)).
- To add xvidapi videos, read [docs/IMPORT.md](docs/IMPORT.md) and [docs/HOSTING.md](docs/HOSTING.md) first.
  **Whole catalog** is the default choice; it imports everything that fits your database (from this page or by
  command line) and stops cleanly at the storage target (80 % of your limit).

## Step 10. Check your site

Open these and check that they look right:

- your home page, a category page, a video page and a watch page (the video plays),
- a search,
- your site on a phone,
- `https://your-domain/sitemap.xml` and `https://your-domain/robots.txt`,
- the admin: Overview, All videos, Import videos, Speed & protection (section **Protection against floods**: every
  protection "On", the search engines listed, Under attack mode "Off").

If you switched the site off in step 3, switch it on again now.

## Theme files changed in 1.2.0

If you edited a theme, compare your copy with these files of the new release before you upload your copy again.
Files that are not listed are unchanged since 1.1.0, apart from the short license comment that 1.2.0 puts at the top of
every JavCMS file (see the License section of [CHANGELOG.md](CHANGELOG.md); it does not change your pages).

<!-- BEGIN theme-files -->
<!-- theme files: 1.2.0 vs 1.1.0 -->
- **New in every theme:** `html/public/list_note.html`, `html/public/provider_block.html`, `html/public/subcats.html`
- **Changed in every theme:** `html/index/index.html`, `html/vod/detail.html`, `html/vod/play.html`, `html/vod/search.html`, `html/vod/show.html`, `html/vod/type.html`
- `template/crimson/`: changed `html/index/list.html`, `html/index/slide.html`, `html/label/sort.html`, `html/public/catchips.html`, `html/public/foot.html`, `html/public/head.html`, `html/public/include.html`, `html/public/map.html`, `html/public/meta.html`, `html/public/metavars.html`, `html/public/paging.html`, `html/public/vodbox.html`, `html/vod/index.html`
- `template/crimson-modern/`: changed `html/label/sort.html`, `html/public/head.html`, `html/public/include.html`, `html/public/map.html`, `html/public/meta.html`, `html/public/metavars.html`, `html/public/paging.html`, `html/public/vodbox.html`, `html/vod/index.html`
- `template/ember/`: changed `html/index/list.html`, `html/index/watch.html`, `html/public/catrail.html`, `html/public/foot.html`, `html/public/head.html`, `html/public/include.html`, `html/public/map.html`, `html/public/metavars.html`, `html/public/nav.html`, `html/public/paging.html`, `html/public/vodbox.html`, `html/search/index.html`, `html/vod/embertags.html`, `lang/ar.json`, `lang/de.json`, `lang/en.json`, `lang/es.json`, `lang/fr.json`, `lang/hi.json`, `lang/id.json`, `lang/ja.json`, `lang/ko.json`, `lang/ms.json`, `lang/pt.json`, `lang/ru.json`, `lang/th.json`, `lang/tl.json`, `lang/tr.json`, `lang/vi.json`, `lang/zh.json`
- `template/ember-modern/`: changed `assets/css/thermal.css`, `html/index/watch.html`, `html/public/foot.html`, `html/public/heatmap.html`, `html/public/include.html`, `html/public/meta.html`, `html/public/metavars.html`, `html/public/paging.html`, `html/public/vodbox.html`, `html/vod/index.html`, `lang/ar.json`, `lang/de.json`, `lang/en.json`, `lang/es.json`, `lang/fr.json`, `lang/hi.json`, `lang/id.json`, `lang/ja.json`, `lang/ko.json`, `lang/ms.json`, `lang/pt.json`, `lang/ru.json`, `lang/th.json`, `lang/tl.json`, `lang/tr.json`, `lang/vi.json`, `lang/zh.json`
- `template/honey/`: changed `html/gbook/index.html`, `html/index/list.html`, `html/index/slideitem.html`, `html/public/foot.html`, `html/public/head.html`, `html/public/include.html`, `html/public/meta.html`, `html/public/metavars.html`, `html/public/nav.html`, `html/public/paging.html`, `html/public/related.html`, `html/public/vodbox.html`
- `template/honey-modern/`: changed `html/gbook/index.html`, `html/index/hive.html`, `html/index/list.html`, `html/index/slide.html`, `html/public/foot.html`, `html/public/head.html`, `html/public/include.html`, `html/public/map.html`, `html/public/meta.html`, `html/public/metavars.html`, `html/public/nav.html`, `html/public/paging.html`, `html/public/related.html`, `html/public/vodbox.html`
- `template/jav/`: changed `html/block/copy.html`, `html/block/head.html`, `html/block/include.html`, `html/block/page.html`, `html/block/vod_box.html`, `html/block/vod_facts.html`, `html/block/vod_meta.html`, `html/index/list.html`, `html/label/sort.html`, `html/map/index.html`, `html/public/map.html`, `statics/css/jav.css`, `statics/js/jav.js`
- `template/jav-modern/`: changed `html/block/copy.html`, `html/block/head.html`, `html/block/include.html`, `html/block/page.html`, `html/block/vod_box.html`, `html/block/vod_facts.html`, `html/block/vod_meta.html`, `html/index/list.html`, `html/label/sort.html`, `html/map/index.html`, `html/public/map.html`
- `template/javcms/` (JAVCMS Classic): not in this release; see "What changes in short" above.
- `template/javcms-modern/`: changed `html/index/list.html`, `html/public/foot.html`, `html/public/include.html`, `html/public/meta.html`, `html/public/metavars.html`, `html/public/nav.html`, `html/public/paging.html`, `html/public/related.html`, `html/public/vodbox.html`
- `template/neon/`: changed `html/index/icons.html`, `html/index/list.html`, `html/index/slide.html`, `html/public/filter.html`, `html/public/foot.html`, `html/public/include.html`, `html/public/meta.html`, `html/public/metavars.html`, `html/public/nav.html`, `html/public/paging.html`, `html/public/vodbox.html`
- `template/neon-modern/`: changed `html/index/banner.html`, `html/index/board.html`, `html/index/list.html`, `html/index/notice.html`, `html/index/slide.html`, `html/public/catrail.html`, `html/public/filter.html`, `html/public/foot.html`, `html/public/head.html`, `html/public/include.html`, `html/public/map.html`, `html/public/meta.html`, `html/public/metavars.html`, `html/public/nav.html`, `html/public/paging.html`, `html/public/shot.html`, `html/public/vodbox.html`
- `template/onyx/`: changed `html/index/banner.html`, `html/index/list.html`, `html/index/slide.html`, `html/public/foot.html`, `html/public/head.html`, `html/public/include.html`, `html/public/listside.html`, `html/public/meta.html`, `html/public/nav.html`, `html/public/paging.html`, `html/public/vodbox.html`, `html/public/vodvars.html`, `html/search/index.html`, `html/vod/index.html`
- `template/onyx-modern/`: changed `assets/css/onyx-modern.css`, `html/index/banner.html`, `html/index/list.html`, `html/index/slide.html`, `html/public/foot.html`, `html/public/head.html`, `html/public/include.html`, `html/public/listside.html`, `html/public/meta.html`, `html/public/nav.html`, `html/public/paging.html`, `html/public/vodbox.html`, `html/public/vodvars.html`
- `template/topaz/`: changed `html/index/heroitem.html`, `html/public/include.html`, `html/public/meta.html`, `html/public/paging.html`, `html/public/types.html`, `html/public/vodbox.html`, `html/public/vodvars.html`, `lang/ar.json`, `lang/de.json`, `lang/en.json`, `lang/es.json`, `lang/fr.json`, `lang/hi.json`, `lang/id.json`, `lang/ja.json`, `lang/ko.json`, `lang/ms.json`, `lang/pt.json`, `lang/ru.json`, `lang/th.json`, `lang/tl.json`, `lang/tr.json`, `lang/vi.json`, `lang/zh.json`
- `template/topaz-modern/`: changed `assets/css/tp.css`, `html/index/slide.html`, `html/public/include.html`, `html/public/meta.html`, `html/public/paging.html`, `html/public/types.html`, `html/public/vodbox.html`, `html/public/vodvars.html`, `lang/ar.json`, `lang/de.json`, `lang/en.json`, `lang/es.json`, `lang/fr.json`, `lang/hi.json`, `lang/id.json`, `lang/ja.json`, `lang/ko.json`, `lang/ms.json`, `lang/pt.json`, `lang/ru.json`, `lang/th.json`, `lang/tl.json`, `lang/tr.json`, `lang/vi.json`, `lang/zh.json`
- `template/velvet/`: changed `html/index/list.html`, `html/public/catrail.html`, `html/public/foot.html`, `html/public/include.html`, `html/public/meta.html`, `html/public/nav.html`, `html/public/paging.html`, `html/public/slide.html`, `html/public/vodbox.html`
- `template/velvet-modern/`: changed `html/index/feature.html`, `html/index/list.html`, `html/public/catchips.html`, `html/public/foot.html`, `html/public/head.html`, `html/public/include.html`, `html/public/meta.html`, `html/public/nav.html`, `html/public/paging.html`, `html/public/vodbox.html`
<!-- 414 files in 17 themes (JAVCMS Classic not in this release) -->
<!-- END theme-files -->

## If something goes wrong: back to 1.1.0

1.2.0 adds tables and columns; 1.1.0 ignores them. To go back:

1. Stop every import first. With a terminal:

   ```
   php tools/upgrade.php --prepare-rollback
   ```

   It pauses every import job and every automatic import of every source and writes the waiting view counts.
   Without a terminal: open Import › Import videos, pause every job and automatic import of each source.
   **Do not skip this:** 1.1.0 does not know xvidapi and would send xvidapi jobs to AVDB.
2. Copy your backed-up 1.1.0 files back over the site folder (keep your `application/database.php`,
   `application/route.php` and `application/extra/`; from `application/extra/` copy only `version.php` back from your
   backup, so the admin shows 1.1.0 again).
3. **Right after copying**, delete everything inside the folder `runtime/temp/` (keep the folder itself). It holds the
   theme pages that 1.2.0 prepared, and 1.1.0 cannot show them: **until they are gone, every page of your site shows an
   error page ("500").** In your hosting panel: File Manager › open `runtime/temp` › select all › Delete.
   Without a file manager: sign in to the admin (it keeps working) and click **Clear cache** in the top bar at once;
   it empties the same folder.
4. Click **Clear cache** in the admin (also when you emptied the folder by hand), then open your home page and a video
   page to check that they show again.

If the data itself is damaged, restore the database: Admins & backup › Backup & restore › **Restore database** tab ›
**Restore** next to your backup (type `RESTORE` to confirm), or import your exported file in phpMyAdmin.

Ask for help on Telegram: https://t.me/avdb_api . Tell us what you see and which step you were on.
