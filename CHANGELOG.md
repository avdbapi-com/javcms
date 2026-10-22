# Changelog

## 1.2.0 (2026-10-22)

Everything that changed since 1.1.0 (2026-10-15).
How to move your site to this release: [UPGRADE.md](UPGRADE.md). Which hosting you need: [docs/HOSTING.md](docs/HOSTING.md)

### License

- **JavCMS Community License 1.0** from this release (file `LICENSE`): use JavCMS free of charge, also for your own
  website that earns money, copy it, change it and share it; nobody may sell it, and charging others for installing,
  setting up, running, supporting, changing or handing over JavCMS, or for hosting plans with JavCMS installed, counts
  as selling. Shared copies stay free and carry no money-making code or links (ads, affiliate codes, paid links,
  miners) of the person who shares them. MacCMS, ThinkPHP and the other third-party parts keep their own licenses. Copies of 1.1.0 and earlier stay under the licenses they were published with (Apache-2.0; MIT for the
  files that said MIT).
- JavCMS PHP, template, CSS and JavaScript files start with a short comment that names JavCMS, the license and the
  official sources (except a few generated or data files and two readable rewrites of third-party jQuery plugins). It
  is an ordinary code comment; nothing changes in your pages.
- `NOTICE` names the third-party code (also the changes that came from the MacCMS editions RamCMS and ApiiCMS), and
  `NOTICE.CHANGED-FILES.txt` lists the changed files of the MacCMS release and of the bundled editor libraries
  (TinyMCE, KindEditor, CKEditor, UMeditor), CodeMirror and Layui, each compared with its original release; it also
  says which changes were already in the copy JavCMS received from ApiiCMS. ApiiCMS files that JavCMS ships unchanged
  (five RSS templates of each jav theme, a set of player stubs) carry no JavCMS notice.
  Third-party license notices that an earlier translation or formatting pass had altered are back to their original
  text (MacCMS and ThinkPHP headers and author lines, README files, the editor library and CodeMirror headers).
- The installer's first page shows a short summary of the JavCMS Community License instead of the old "User
  Agreement" (which did not match the license), and its footer no longer says "All Rights Reserved".
- The installer, the admin sign-in page and the test e-mail call JavCMS free, source-available software; they no
  longer call it open source (a license that forbids selling is not an open source license).
- `LICENSE` and `NOTICE` also name the JetBrains Mono, Poppins and Rubik fonts, the Material Icons in the topaz
  sprite, the Droid Sans Mono watermark font, MathQuill (LGPL) and the stui-based templates that the jav themes were
  built on. CKEditor's own `LICENSE.md` is now part of the package.
- **JAVCMS Classic is not in this package; JAVCMS Modern is the new default theme.** The theme `javcms` loaded the
  base stylesheet of a third-party tube theme made for KVS, that theme's IcoMoon icon font and a stylesheet of the 8X
  theme pack (`orang.css`); their authors and licenses are not known, so they may not go into a free download. New
  installs start with `javcms-modern` (JAVCMS Modern), which shows the same pages with JavCMS's own stylesheets. The
  release has 17 themes. Sites that use JAVCMS Classic: see [UPGRADE.md](UPGRADE.md). `LICENSE` (Part D) and
  `NOTICE` say where the javcms-modern page markup comes from; those templates say "Partly based on a third-party
  theme" in their notice.
- The admin no longer uses the DashLite stylesheet (a commercial template that may not be shared for free). The new
  `static/css/admin_base.css` replaces it and contains no DashLite rule: its reset, heading sizes, badges and spacing
  follow Bootstrap 5.3.3 (MIT), and its colours are those of the JavCMS admin. Some headings, buttons and badges in the
  admin look a little different.
- The UMeditor editor no longer carries the 349 Baidu Hi emoticon images that earlier releases had saved into
  `static/editor/umeditor/dialogs/emotion/images/` (their author and license are not known), and its emoticon button
  is gone (without the local images it would load them from Baidu's servers). Every other editor button stays.
- The package no longer carries the unused Pear Admin Layui files (`static/pear/`, `static/admin/css/`,
  `static/admin/images/`) and 28 unused images of an old MacCMS template in `static/images/pro/`, `pro-home/` and
  `other/`. No page used them; nothing changes on your pages.
- **Captcha font:** the six fonts that came with the captcha library (freeware fonts of other authors, one of them free
  for personal use only) are not in the package any more. The captchas use a copy of the Droid Sans Mono font that the
  package already ships (Apache-2.0): `vendor/topthink/think-captcha/assets/ttfs/DroidSansMono.ttf`. The unused
  captcha background photos (`assets/bgs/`) are gone too.
- **Player images:** the loading animation and the progress knob of the ArtPlayer page (images of the ArtPlayer demo
  pages that showed Bilibili's "TV" character and logo) are replaced by simple images made by JavCMS, and four unused
  files of that folder are gone (`state.svg`, `subtitle.svg`, `1s_blank.mp4`, `javcms-online.gif`).
- **jav theme:** the background strip of the watch page (`statics/img/play_bg.webp`, an image of unknown origin) is
  replaced by a strip of cinema seats made by JavCMS, and the play button on the video pictures (`statics/img/play.png`,
  from a third-party template) by one made by JavCMS.
- **neon theme:** the category icons of the header, the heading marker, the play button on the video pictures and the
  signs of the password-reset form are new images made by JavCMS (the old ones were icon art of a third-party theme
  pack); 28 icon images of that pack that nothing used are gone.
- **Editor emoticons:** the KindEditor and UEditor editors no longer have an emoticon button, and the package no longer
  carries the emoticon images of other authors inside the editor and UI libraries: KindEditor's QQ faces, the Baidu Hi
  preview pictures of UEditor and UMeditor, the Sina Weibo faces of Layui and the Baidu Tieba faces of MacCMS (no page
  used the last two). Every other editor button stays. The file-type icons that UEditor puts next to attached files
  (copies of application icons such as Word, Excel and PDF) are replaced by simple file signs made by JavCMS.
- **Flash player:** `static/player/play.swf` (a Flash player with parts whose license is not stated) and its player
  stub `static/player/flv.js` are gone. Browsers no longer run Flash, and no player of the package used them.
- **Unused files without a license:** `static/layui/lay/modules/dropdown.js` and `dropdown.css` (layui_dropdown 2.3.4;
  neither the files nor the project state a license, and the Layui build has its own dropdown) and
  `static/js/jquery.lang.js` (a simplified / traditional Chinese text switch without author or license) are not in the
  package any more. Nothing used them; nothing changes on your pages.

### A second video source: xvidapi

- **xvidapi** (about 6.6 million international videos) next to AVDB, on one page: **Import › Import videos**,
  with one tab per source. Use AVDB only, xvidapi only, or both.
- **Whole catalog by default**: "Whole catalog (recommended)" is first and preselected and imports everything that fits
  your database, from the admin page or by command line (both import everything). Newest 10,000 / 50,000 / 100,000 /
  1,000,000, your own number and one category stay as choices.
- **Size estimate before every import**: database space, the database after the import against your limit,
  the highest disk use while importing (MySQL 8 binary logs included), requests and time, import time on your server
  with the tab open, closed and by cron, and where the videos come from (xvidapi snapshot files or id windows). When the
  database cannot hold everything, it says "Stops at about N videos when the database reaches X GB": the import brings
  the newest videos first and stops cleanly at the storage target (80 % of your limit), the only stop.
- **Your database limit**: asked the first time; imports pause before the database gets full, with a readable message,
  instead of breaking the site.
- **No cron needed**: a started import continues by itself with the tab closed, one short step after a visitor's page
  has been sent (at most every 30 seconds, never inside a visitor's request; needs PHP-FPM or LiteSpeed, the page says
  when the server cannot do it). **Command-line importer** `tools/import.php` (optional, faster): runs by cron every
  5 minutes, at low priority, and continues where it stopped. The page prints the cron line with the right PHP
  program and shows "Cron last ran". Big jobs ask for one checkbox with the numbers (against a misclick).
- **xvidapi snapshot**: when xvidapi offers its export snapshot, the whole catalog is a download of about 66 checked
  files plus feeds of changes and removed videos instead of 13,194 requests; a stopped download continues where it
  stopped. Older xvidapi: id windows, as before.
- **Categories**: Grouped (about 12 categories, recommended) or Mirror (one per xvidapi category), all under one menu
  entry ("International" by default). No filter is on by default: clips under 60 seconds, the category "Unknown" (as
  "Other") and SD videos are imported unless you tick their filter.
- **A big import shows up as new arrivals** in a site that already has the other source, in its own order, with the
  real upload date as release date.
- **Gentle on the providers**: one request every 5 seconds per site, shared by every runner; waits when asked, backs
  off on errors, pauses on a browser check instead of retrying; never a request caused by a visitor.
- **Provider takedowns**: a removed video is hidden after two checks at least 24 hours apart; a safety stop hides
  nothing when a check would hide more than 2 % or 5,000 videos; you decide when to delete hidden videos for good.
- **Deleted videos stay deleted**: automatic and big imports no longer bring back videos you deleted.
- **Undo with the time shown first**, and **Remove all videos from <source>** with a typed confirmation.
- One automatic import schedule for both sources (they run one after the other).

### Fast with millions of videos

- **Search index**: the first search for a word takes well under a second in every language, also with 7 million
  videos. Whole words, the last word also as a beginning ("ama" finds "amateur"), newest first, every result page
  reachable. Single words, and single-word tags that the term map proves exact (no other tag or category word
  contains them), have numbered pages to the last one; longer searches show "Older results". Actor names, the other
  tags (plural or compound words, tags that are also category names), several names at once and names with special
  characters keep the exact old results through the text match: a very common one with "10,000+" and numbered pages
  for its first 10,000 videos, the others with "N+ videos" and a link to the next page, and "Older" to the oldest
  video. Saving or re-importing old videos keeps a proven tag's numbered pages (up to 1,000 such videos per tag).
- **Page cache** in front of everything: cached pages in about 2 ms, more than 1,000 pages per second on a small VPS.
  It has a size limit, and a deleted video disappears at once.
- Category counts and the sitemap are prepared in the background; no visitor waits for a count. The sitemap files are
  built ahead and stored by housekeeping, `tools/upgrade.php`, the installer and **Finish upgrade**, so search engines
  get stored files; a file that was never built is answered from a small rows list per sitemap block
  (`runtime/scale/sitemap_rows/`, `tools/upgrade.php --sitemap-rows-budget=N`) without reading the video table.
- Browsers and CDNs keep the pictures, scripts, styles and fonts of the site and of the themes for 30 days (`.htaccess`,
  `web.config` and the server examples in `deployment/`); the ad files in `static/jcx/` keep the server default.
- **No page limits**: every list, category, tag, actor and search result is reachable, down to the oldest video,
  also with 7 million videos (a page map for lists by date, rank files for lists by views or likes, a term map for
  single-word searches and the single-word tags it proves exact). Pagers have first, last and jump links. Filtered
  lists and very common tags show "10,000+" and continue with "Older"; actor pages and rarer names show "N+ videos"
  with "Older" on every page.
  Deep pages are not indexed by search engines after page 50 by default, but they are served.
- Two new list indexes ("Finish upgrade") and an optional slim-index tool (`tools/db-tune.php --slim-indexes`) for
  sites above a million videos.

### Protection against floods and crawlers

- Protection on by default with high values (owner decision 2026-10-17): per-visitor request limits of 300 searches,
  filters or deep pages and 2,400 other pages per minute per visitor network (adjustable on Speed & protection), never
  one limit for the whole site; visitors behind one mobile carrier address are not blocked together.
- Search engines are never blocked: Googlebot, Bingbot, YandexBot, DuckDuckBot and Applebot, checked by their
  published IP lists (refreshed daily) or reverse and forward DNS, are never limited or turned away. The package
  ships those IP lists (`application/data/crawlers/`), so the search engines are recognised from the first request;
  the installer and **Finish upgrade** download the current lists at once, and Speed & protection shows which list is
  still the one shipped with JavCMS.
- Under attack mode (off by default): stricter limits for the time of an attack, search engines still allowed.
- Word indexes (FULLTEXT) for the title, actor, tag, category and director filters in every install.
- A few slots for heavy work, a time limit on every visitor database query, and a short "busy" page that retries by itself.
- Proxies and Cloudflare Tunnel: a notice when all visitors seem to come from one address, with one-click
  "Trust this proxy" and a Tunnel switch; a notice when one address sends pages with many different browsers (a CDN
  or firewall in front of the site?): its limits stay per address until you trust it by hand.
- Copy-paste Cloudflare rules (cache, rate limit, callback skip) with your real admin address, and origin-lock
  snippets.
- Search engines and AI crawlers: search pages are no longer crawled ([docs/SEO.md](docs/SEO.md)).

### New admin page: Speed & protection

- One status line each for the search index, counts and list indexes, protection, database space and cron, with one
  button each; red notices that say what to do; details with the limits of your site, the page cache, refused pages per
  hour and the busiest visitor networks.

### Installer

- New question **"Which catalogs will you import?"** (AVDB, xvidapi, both, decide later): only the chosen categories
  are turned on. With xvidapi it asks for the name of the genre menu (International or Western).
- Neutral texts for new sites: no fixed "JAV" category titles; site description and keywords that fit the chosen
  catalogs. The site name you choose is the main heading of the home page.
- The new tables (search index, page counts, importer) are created during the install.

### Themes

- All 17 themes work with AVDB only, xvidapi only, or both: empty categories are hidden, the xvidapi genres appear in
  the menu on xvidapi-only sites, an International block on the home page of sites with both, category chips on
  category pages, quality badges instead of codes on xvidapi cards, small pictures shown without blur, and Arabic pages
  show leading numbers correctly.
- Fixed: the video page of a video without a play list could stop with an error in some themes; every theme now checks
  the list first.
- Fixed (ember-modern, onyx-modern): the footer name and the header (search and MENU) fit narrow phone screens (360 px)
  in every language, and a Latin site name keeps its start on right-to-left pages.

### Documentation

- New [docs/HOSTING.md](docs/HOSTING.md): which hosting for which catalog size, cron lines for cPanel, DirectAdmin and
  Plesk, MySQL 8 binary logs.
- [docs/IMPORT.md](docs/IMPORT.md), [docs/MAINTENANCE.md](docs/MAINTENANCE.md), [docs/SECURITY.md](docs/SECURITY.md)
  and [UPGRADE.md](UPGRADE.md) rewritten for the new features.

### Known limits of 1.2.0

Measured with 7.1 million videos on a 2-core test server; every list stays exact, these pages are only slower or show
less. These apply from size level 3 (1,000,000 published videos or more; Speed & protection › Details shows your
site's level); below it, lists, totals and landing descriptions count as in the first 1.2.0. Details in
[docs/HOSTING.md](docs/HOSTING.md) ("Known slower cases"):

- Actor pages and the tag pages that the term map cannot prove exact use the slower text match. Their first page
  shows "N+ videos" with links to the next page and to "Older" (about 0.2 s of database time on the first visit). A
  very common name then numbers its first 10,000 videos (pages 2-501, about 0.75-1.3 s each on the first visit) and
  continues with "Older"; a rarer name (most actor names) keeps "N+ videos" with "Older" on every page, and on a big
  site its pages far down can get the simpler page on a cold database.
- A category-name filter that matches a big part of the catalog takes 0.6-0.8 s on its first visit.
- Names with a word under 3 letters beside a longer one ("Yuvin B"), and tags or actors whose videos are all old, read
  the name's matches once: a full first page in up to 1.4-1.8 s on the first visit. Names written in Chinese, Japanese
  or Korean take up to about 2.3 s, and a director name in these scripts can get the simpler page on a big site (a later
  release adds them to the term map).
- A filter with several long names at once (longer than the search length setting) is slower on its sorted and paged
  links.
- A name without a word index (only words under 3 letters such as the category "VR", a code or name with "_", "%" or
  an apostrophe in it, or a word that more than 50,000 videos hold) reads the plain text match for up to about 2.5 s:
  on a cold big site its first page shows fewer than 20 videos, or none, with "Older" when its videos lie deeper than
  about the newest 100,000 ("VR": 3 of 20 on the first visit, 20 on the next one).
- A search engine that crawls a big site right after a restart can get a few heavy pages (filter pages, list pages
  after page 50) as the lighter page in its first seconds (HTTP 200, indexable, with the newest videos and a "Try
  again" link): 0-3 of 595 pages in our runs with Googlebot at 10 pages per second, none after the first 15 seconds. It
  gets the full page when it comes back.
- Actor pages, and the tag pages of the slower text match (see the first point), of very common names (every word of
  the name is in more than 10,000 videos, for example the tag "Creampie") no longer start their description with the
  number of videos (1.1.0 started it with the exact number, for example "N videos tagged Creampie."): an exact count
  would read too much on their first visit. Single-word tags that the term map proves exact keep it
  ("10000+ videos tagged ..."). The page stays indexable and its list exact.
- With all 16 AVDB languages the search index is about 0.7 GB bigger for both full catalogs (a tight database limit
  keeps English and the site language only).

## 1.1.0 (2026-10-15)

Everything that changed since the 11-theme release of 2026-10-10.
How to move your site to this release: [UPGRADE.md](UPGRADE.md)

### Themes

- **7 new Modern themes**, so there are now **18 themes**:
  Ember Thermal, Onyx Grid, Velvet Premiere, Honey Nectar, Neon Nocturne, Crimson Ink and Topaz Prism.
  Each one is a new design with its own layout, icons, logo style, colours and motion effects,
  not a recolour of its classic sibling.
- Modern themes turn off heavy motion for visitors who ask their device for less motion,
  and skip 3D background effects on slow or low-memory devices and in data-saving mode.
- **Visitor theme switcher**: a small floating button lets visitors pick a theme. Their choice is remembered.
  You choose which themes are offered and in which corner the button sits. On in new installs.
  The button now waits at the end of the page, in its own free space, so it never covers video titles,
  text or ads. Keyboard users can reach it from anywhere on the page. It has one clear focus ring
  in light and dark mode.
- **Themes page** in the admin with a preview picture of every theme, **Preview** (only in your browser)
  and **Use this theme**.
- **Your own site icon** in every theme (upload it once in Site identity).
- **Legal pages** in the footer of every theme: terms, privacy, DMCA, cookies and an optional 2257 page,
  with example texts you can edit. The example texts are English: on pages in other languages they are now
  marked as English, so they read in the right direction on Arabic pages too.
  JAVCMS Classic, JAVCMS Modern and Ember show them with clear headings, lists and readable links.
- Optional **18+ question** and a **cookie notice**.
- One consistent icon set per theme.
- **Menus stay readable:** while a theme menu, drawer or bottom sheet is open, the sticky ad bar,
  corner boxes and side rails hide. They come back when the menu closes. All 18 themes.
- **Less page jumping while fonts load:** all Modern themes and Crimson, Topaz and Honey use matched
  stand-in fonts until the web font arrives, so text no longer jumps (most visible on Arabic pages).
- More text in the visitor's language: region names such as "Japan" in the filters, the "Hot searches" list
  of JAVCMS Modern, and the footer line of Neon and Neon Nocturne.
- Many fixes in all themes: complete rows of video cards at every screen width, less page jumping while loading,
  visible keyboard focus, better text contrast in light and dark mode, and phone layout fixes
  (for example the Crimson watch page player on phones, the JAV Modern search heading on phones,
  the Honey watch page width on phones and the Honey Nectar home page banner under the header).
- Ad boxes line up with the content column in Velvet, Velvet Premiere, Honey, Honey Nectar and Onyx Grid.
- Scrolling rows: the hidden arrow at the start or end of a row is no longer an invisible keyboard stop.

### Ads

- **New ads manager**: Site & theme › **Ads & tracking code**, with the tabs Overview, My ads, Places,
  Video ads, Network & files, Stats and Settings.
- **58 example ads** in **19 formats**: banners in 16 common sizes, banner pairs for computer and phone,
  sticky bars, side rails, expandable banners, corner boxes, notification-style boxes,
  full-screen ads between pages, exit-intent panels, popunders (network), web push (network),
  cards in video lists, recommendation widgets, sponsored labels, video in the page, HTML5 ads,
  home page takeovers and text links. Examples are off and visible only to you.
- **Add an ad in 3 steps**: what, where, check. Pick the place by clicking it on a preview of your own page.
  See the result on a computer and a phone before you switch it on.
  For script-only ads (such as popunders) step 3 explains why the preview shows no picture.
- **18 places** on your pages. Each place can be switched off. An advanced option moves a place in your theme.
  The Places tab has a **Computers / Phones** choice, so it is readable on a phone.
- **Video ads before the video (pre-roll)**: your own mp4 / webm file or a link (also .m3u8),
  or VAST tags from ad networks (up to 5, tried in order). Skip after 5 or 10 seconds, no skip (up to 15 seconds),
  a 6-second bumper, or two ads in a row. Starts when the visitor presses Play, or automatically and muted.
- **Rewarded video**: visitors can watch a short video to hide ads for 1 to 24 hours.
- **Network code** with presets: any code, banner in a place, popunder, notification-style box, web push,
  MultiTag, ExoClick zones, full-screen, VAST, direct links, native widgets, sticky bars.
- **Root files** your network asks for: `ads.txt`, push worker files and verification files.
  If your site folder is not writable, you get a download button.
- **Targeting**: devices, page types, dates, weekdays, hours, languages, categories, countries
  (when your server or Cloudflare sends the country), and "everyone / only guests / everyone except VIP members".
- **How often**: share between ads in the same place, limits per visitor, delay, scroll or exit triggers.
- **Settings**: one main switch for all ads, no ads for members or chosen VIP groups,
  "wait for consent", and **Safe limits** that keep your site inside Chrome's ad rules.
- **Stats**: views, seen, clicks, closes, video starts, completions, skips and errors for 7 or 30 days.
  No visitor data is stored.
- **Health check** that tells you when your pages or cache serve old ad files, when ads are off,
  and when one page type has too many ads.
- **No empty ad boxes:** an ad in the first screen now appears together with its picture, without waiting
  for the visitor to tap or scroll. Ads load after your page and never become its largest element,
  so they do not slow down the first view.
- **When the main switch is off, your pages carry no ad code at all.**
- **No video in sticky ads:** sticky bars, corner boxes, notification boxes, side rails and overlays use a picture.
  Videos play only in a slot in the page (video in the page, out-stream, the phone video of the home takeover).
- **Phones:** the slide-up and corner boxes leave the theme switcher's corner free and wait while the cookie
  notice, the player or a menu is in the way. At most one ad inside the content per screen height.
- **Ad texts in the visitor's language:** the push-notification example, the carousel buttons and the
  rewarded-video texts follow the language of the page. The push example no longer moves the page.
- **My ads** counts your own ads only. The examples sit apart under the **Examples** chip
  (bulk actions there offer only Delete, because examples are always off).
- Fixed: the list chips, the **Edit** button of a row and the "30 days" Stats view did nothing.
- The Overview tab has a shortcut to your analytics code.
- The ad settings are split per page type when they grow, so each page loads only the ads it can show.
- Ads never cover the theme switcher or the cookie notice, and space is reserved so the page does not jump.
- New permissions: you can decide which admins may manage ads and which may paste HTML / JavaScript code.
- Old ad files from earlier versions can be imported as code ads, and code from the old tracking field
  can be moved into an ad.

### SEO

- **Site identity** page rebuilt: site name, **home page title** and **description** with a live Google preview,
  domain, contact email.
- **Site icon (favicon) upload**: one square picture becomes the full icon set for browser tabs, Google results
  and phone home screens. Choose the JavCMS icon, your own icon or each theme's own icon. Brand colour for phone browsers.
- **Share picture** for social networks and links to your profiles.
- **Code & analytics** tab: Google Analytics 4 ID, code in the page head, code at the end of every page,
  and "wait for consent".
- **Languages** page: default language, languages visitors can choose, languages search engines may show,
  and home page texts per language.
- The home page description in another language now uses your text for that language, or else that language's
  home page title. It no longer falls back to the English description.
- **Search titles & descriptions** page rebuilt: a checklist of 14 checks, title style and separator,
  previews, custom title patterns, your own titles for tags and actresses,
  "What Google may show" (tag and actress pages, hide list pages after page N, hide the whole site),
  and **site verification** for Google, Bing, Yandex and Naver.
  The Titles tab starts with a **Home page** card and its Google preview.
- **Category editor** with a **Search engines** section and a live Google preview.
- **Sitemap & robots.txt** page: sitemap settings (pages per language, video details, videos per file),
  robots.txt modes (Recommended, Allow everything, Custom) with a preview, and **IndexNow** for Bing, Yandex,
  Naver and Seznam (off by default).
- Search results, filter pages, member pages, comments and empty pages are kept out of search engines.
- **Hidden categories are really hidden:** the pages of a hidden category answer "Page not found" (404)
  and tell search engines not to index them. The category also leaves the menus and the home page at once.
- Search pages use the address form `/search/?wd=...` as their canonical link. Words with a `/` no longer
  lead to a "not found" page.
- Pages, feeds, sitemaps, styles and scripts are sent compressed on Apache (new rules in `.htaccess`).
- RTA label for parental-control filters (on by default).

### Admin

- **New admin layout**: tasks grouped in the sidebar (Start here, Site & theme, Videos, Import,
  Search engines, Members & comments, Articles, Admins & backup), a search box (press `/`),
  **Clear cache** and **View site** in the top bar, and an account menu.
- Rarely used tools are hidden under **Show advanced tools**.
- **Overview** page with a 9-step setup checklist, site status and today's numbers.
  Step 7 has two buttons: **Ads** and **Analytics code**.
- **Better admin search:** every word you type must match, so "logo" finds the logo setting and not "Log out".
  New entries for "Analytics and tracking code" and "Home page title".
- **My account & password**: change your password and choose your own admin language.
  The Overview warns you while your admin address is still `javcms.php`.
- **Lock screen**: lock the admin while you are away.
- **Traditional Chinese** admin language added (now English, Vietnamese, Simplified and Traditional Chinese).
- Lists rebuilt with filters, clear bulk actions that show how many items they change, named confirm
  questions and empty-state help. Confirm questions now read naturally for one item
  ("Permanently delete this item?", "Delete the only item?").
- Editors have **Undo changes**. Buttons and menus are easier to tap on phones.
- **Category editor rebuilt**: Basics (with a **Visible on the site** switch), Search engines and Advanced.
  Hiding or showing a category changes the site at once; you do not need to clear the cache.
- Role editor rebuilt with presets and clear groups. Rights that are enough to take over the whole site
  carry a red **Full access** mark.
- **Admin accounts:** an admin can only give rights it has itself. The main admin and admins with more rights
  show as **Locked** for everybody else.
- **Backup & restore**: one-click backup of everything, download, restore with a clear confirm.
- **Run SQL** only reads by default; changes need an extra confirm and are checked before they run.
  **Find & replace in database** shows the exact count before it changes anything.
- **Security scan** can also find script code injected into your content and remove it after a backup.
- Scheduled tasks: the **Clear cache** task works again, and deleting a task asks a named question.
- A damaged or fake picture upload now shows a clear message instead of an error page.
- Fixed: **Related videos** and **Related articles** in the editors were never saved.
- Fixed: on Languages, unticking a language and ticking it again lost its "Show in Google" tick.
- Fixed: **Move** in a bulk action with a very long selection.
- Fixed: a category could not be saved when its theme file fields were empty.
- Fixed settings pages that lost values on save (Video player, Uploads & images).
- Page generators no longer write files when static pages are off.
- Signing out also removes your ad preview cookies.

### Import

- **Undo is kept for your 3 newest imports.** The import history keeps your 30 newest imports.
  This keeps the database small (see "Performance and database size").
- Import errors are shown once, with a **Retry** button.
- The AVDB import page uses the new admin style, with one main button per step.
  The preview shows 50 videos per page by default, so it opens much faster
  ("Import all" still works through the catalogue in pages of 1,000).
- If you hid a category (for example Uncensored), new imports put its videos into your hidden category
  and keep it hidden, instead of creating a second, visible copy. Review shows "Uncensored → Uncensored (hidden)".
- Messages of automatic imports are shown in your admin language.
- Fixed: video descriptions with `&` were stored as `&amp;amp;` by new imports.
- "Scheduled tasks" now explains that AVDB import has its own automatic schedule.

### Performance and database size

- **Faster search.** A search for a new word is now one pass over the videos instead of two, with lighter matching
  that finds exactly the same videos. On our test site with over 520,000 videos a new English word takes about
  1 second instead of 2.5, a movie code about 0.6 seconds instead of 4, and words in other languages about
  3 seconds instead of 5. Pages 2, 3 and so on of the same search reuse the first result.
  No database change is needed. The full speed-up needs MariaDB 10.2.3 or newer (see README, "What you need").
- **Automatic clean-up (housekeeping)**: old cache files, compiled templates of removed themes, logs older than
  30 days (logs are capped at 100 MB), old settings snapshots, old verification messages, old referral visits
  and old search results are removed a little at a time. No setup and no cron job needed.
- **Clear cache** in the admin also runs a short clean-up.
- **Import history trimming**: on our test site with over 520,000 videos the database files went from 44 GB to 11 GB,
  and the cache folder from about 297,000 files to about 8,500 files.
- Guests who search no longer start a server session.
- Ads load after the page and never slow down its first view.

### Security fixes

- Admin pages that saved or deleted data without a security check now refuse requests that do not come
  from your own admin page (CSRF protection on save, delete, bulk and tool actions).
- Actions that changed data through a simple link now need a form with a security token.
  Jobs that run in several steps (bulk changes, picture sync, page generators, scraper and API source imports)
  use a one-time key for the following steps.
- **Cross-site scripting (XSS):** a full sweep of the admin. Saved names, settings, link parameters,
  imported rule files and messages are now shown as plain text everywhere in the admin.
- **Server-side requests:** testing or importing from an "Other API source" and the back-link check
  no longer reach local or private network addresses, and they do not follow redirects to other hosts.
- **Admin rights:** a limited admin can no longer change the main admin, change admins with more rights,
  or give itself rights it does not have.
- **Run SQL** refuses commands that read or write files on the server or change server-wide settings.
- Shared scraper rule files can no longer name program functions to run.
- The syndication API (off by default) no longer returns access passwords of videos and articles.
- Crafted page addresses can no longer change the language links that other visitors see on cached pages.
- **Member accounts:** a limit on wrong one-time codes (password reset, sign-up), a limit on password guesses
  per account, a stronger sign-in secret and cookies that page scripts cannot read.
- Member-side fixes: an open redirect, visitor IP addresses taken from headers anyone can fake,
  ratings outside the allowed range, the "add to desktop" shortcut file, and error pages caused by odd addresses.
- Raw theme template files can no longer be downloaded from the `template/` folder.
- The admin sign-in cookie can no longer be read by page scripts or sent from other sites,
  and you get a new session at every sign-in.
- The lock screen signs you out after too many wrong passwords.
- Uploads for the site icon and share picture refuse SVG files and disguised scripts.
- Pasting HTML or JavaScript code into ads needs its own admin right. HTML5 ads run in a closed box.
- Points withdrawals: delete and review now refund and update the member correctly in one step.
- The old add-on install and upgrade buttons were removed (they could run code from a simple link).
- China-only services (QQ and WeChat sign-in, SMS, Chinese image hosts) can no longer be used,
  even when old settings still name them.
- Static files (scripts, styles, pictures, fonts) are now sent with the `X-Content-Type-Options: nosniff` header
  on Apache and IIS (new lines in `.htaccess` and `web.config`).
- Safer HTTPS default: the HSTS header is now sent for 7 days and for your domain only
  (it was one year with all sub-domains, which could lock visitors out of HTTP-only sub-domains such as webmail).
  How to make it stronger: [docs/SECURITY.md](docs/SECURITY.md), "Use HTTPS".
- `crossdomain.xml` no longer lets other sites read your pages through old browser plug-ins.
  Upload the new file over the old one.

### Removed

- **Mid-roll and post-roll video ads.** Videos play in the video provider's own player, so an ad during or after
  the video can never show. Old ads of that kind are marked "Cannot play" and cannot be switched on. Delete them.
  Pre-roll ads (before the video) work as before.
- **The `TVC_VIDEO` / `TVC_URL` pre-roll setting** in `static/player/artplayer/index.html`.
  Use Ads & tracking code › Video ads instead.
- The site keywords field (search engines ignore it). Your old value is kept.
- A few player size fields that themes do not use. Your old values are kept.
- The add-on install, upgrade, enable and disable buttons, and the online update steps.
- The Chinese image-host upload option in Uploads & images.
- Old demo data files of the admin interface.

## 2026-10-10: 11 themes

- Added the Topaz theme (11 themes in total).

## 2026-10-09: clean community release

- First clean community release: 10 themes, 17 front-end languages, AVDB import, installer for PHP 7.4 to 8.5.
