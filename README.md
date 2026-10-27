# JavCMS

JavCMS is a free, source-available website system for video sites: Japanese (JAV), international, or both.
You install it on your own hosting. It fills your site with videos from the AVDB API and the xvidapi API,
in a few clicks or fully automatically, from a few thousand videos up to millions.

JavCMS is based on MacCMS 10 (ThinkPHP 5.0).

- Download: https://github.com/avdbapi-com/javcms/releases/latest (always the newest release)
- Source code: https://github.com/avdbapi-com/javcms
- Live demo: https://javcms.net (adult content, 18+)
- Telegram group: https://t.me/+jj6McO-iYfA0MTM1
- Support: https://t.me/avdbapi_support

## What you get

### 17 ready themes

Every theme works on phones, tablets and computers.
Every theme starts in dark mode, and visitors can switch to light mode.

| Folder | Name |
|---|---|
| `javcms-modern` | JAVCMS Modern (the default) |
| `jav` | JAV Classic |
| `jav-modern` | JAV Modern |
| `ember` | Ember |
| `ember-modern` | Ember Thermal (Modern) |
| `onyx` | Onyx |
| `onyx-modern` | Onyx Grid (Modern) |
| `velvet` | Velvet |
| `velvet-modern` | Velvet Premiere (Modern) |
| `honey` | Honey |
| `honey-modern` | Honey Nectar (Modern) |
| `neon` | Neon |
| `neon-modern` | Neon Nocturne (Modern) |
| `crimson` | Crimson |
| `crimson-modern` | Crimson Ink (Modern) |
| `topaz` | Topaz |
| `topaz-modern` | Topaz Prism (Modern) |

Visitors can pick a theme themselves with the visitor theme switcher: a palette button that every page shows from the
first screen. You can also put it in the site header or show it only at the end of the page, or switch it off.
More: [docs/THEMES.md](docs/THEMES.md)

### 17 languages for visitors

English, Vietnamese, Thai, Indonesian, Korean, Chinese, Japanese, French, German,
Spanish, Portuguese, Russian, Arabic, Turkish, Hindi, Malay and Filipino.
Every theme has a language menu. Video titles and descriptions can be imported in all 17 languages.

### Admin panel in 4 languages

English, Vietnamese, Simplified Chinese and Traditional Chinese.
Each admin picks their own admin language.

### Video import from AVDB and xvidapi

- Built in. No API address to type. Two sources: AVDB (about 524,000 Japanese videos, titles in 17 languages)
  and xvidapi (about 6.6 million international videos).
- The whole catalog is the default (or the newest 10,000, 50,000, 100,000, your own number, one category). A size
  estimate before every import shows the database space, the time and where it stops when your hosting is full: the
  storage target is the only stop.
- Big imports run from the admin page and continue by themselves, or faster by cron with `tools/import.php`; both
  continue where they stopped.
- AVDB: find videos by code, title, actress, studio, category, year and more.
- Import once, or turn on **automatic imports** (every 15 minutes, 1 hour, 6 hours, 12 hours or once a day).
  Automatic imports run even when nobody visits your site. You do not need a cron job for them.
- Undo for recent imports, pause and resume, retry failed items, remove all videos of one source.
- Videos the provider removes are hidden carefully (checked twice, with a safety stop); videos you delete stay deleted.
- Imports run in short steps, so they never block your server for long.

More: [docs/IMPORT.md](docs/IMPORT.md). Which hosting you need: [docs/HOSTING.md](docs/HOSTING.md)

### Ads system

- One place for all ads: **Ads & tracking code** in the admin.
- **58 ready example ads** in 19 formats: banners in all common sizes, sticky bars, side rails,
  corner boxes, full-screen ads between pages, cards in video lists, recommendation widgets,
  HTML5 ads, home page takeovers, text links and more.
  Examples are off and only you see them, until you copy one and switch it on.
- **18 places** on your pages (top of page, under the player, inside video lists, sticky bar and more).
  You pick a place by clicking it on a preview of your own page.
- **Video ads before the video (pre-roll)** with a Skip button after 5 or 10 seconds, a 6-second bumper,
  two ads in a row, VAST tags from ad networks, and a "watch a short video to hide ads" option.
- **Network code**: paste the code your ad network gives you. Ready presets for popunders,
  push, ExoClick zones, VAST, ads.txt and site verification files.
- Schedules, devices, languages, categories, countries and "no ads for members or VIP groups".
- Simple stats (views, clicks, video starts, skips) and a health check.
- "Safe limits" keep your ads inside Chrome's ad rules, so Chrome does not block your ads.
- Built for fast pages: no empty ad boxes, ads load after your page, no video in sticky ads,
  and ads step aside while a menu is open. Ad texts follow the visitor's language.
- With the main switch off, your pages carry no ad code at all.

More: [docs/ADS.md](docs/ADS.md)

### Search engine features (SEO)

- Your own **site icon (favicon)**: upload one picture, JavCMS makes every size for browsers and phones.
- **Search titles & descriptions** for every kind of page, with a live Google preview and a checklist.
- **Sitemap** at `/sitemap.xml` (also per language) and **robots.txt** made for your domain.
- Clean page addresses, canonical links, language links (hreflang) and structured data.
- Site verification for Google, Bing, Yandex and Naver. IndexNow tells Bing, Yandex, Naver and Seznam about new videos.
- Share picture for social networks, Google Analytics 4, legal pages (terms, privacy, DMCA, cookies, 2257)
  and an optional 18+ question.
- Hidden categories answer "Page not found" and drop out of search engines.

More: [docs/SEO.md](docs/SEO.md)

### Fast with millions of videos

- A search index: the first search for a word takes well under a second in every language, also with millions
  of videos, and the next pages open at once.
- A page cache in front of everything: cached pages in about 2 ms.
- Fair limits per visitor network, a few slots for heavy work and time limits on every database query, so floods,
  crawlers and one busy visitor cannot take the site down. Copy-paste Cloudflare rules if you want more.
- **Speed & protection** in the admin shows the state of all of this, with one button per area.

### An admin panel made for beginners

- A setup checklist on the first page that walks you through the first steps.
- A search box: press `/` and type what you need.
- Rarely used tools stay hidden under "Show advanced tools".
- Hide a category with one switch: it disappears from your site at once.
- One-click updates from GitHub, with a backup and an automatic rollback (see "Updates" below).
- One-click database backup, download and restore.
- A security scan for changed files and injected scripts.
- Separate admin accounts with exact rights. An admin can never give more rights than it has.

### Keeps itself small

JavCMS cleans up after itself. Old cache files, old logs, old search results and the details of old imports
are removed automatically, a little at a time. Your videos, members, comments, uploads and backups are never touched.
No setup is needed.

More: [docs/MAINTENANCE.md](docs/MAINTENANCE.md)

## What you need

- **PHP 7.4 to 8.5**, with these extensions: PDO with pdo_mysql, mbstring, cURL, GD, XML, ZIP, fileinfo and OpenSSL.
  The installer checks them for you.
- **MySQL or MariaDB.** MySQL 5.6+ or MariaDB 10.1+ works.
  **MySQL 5.7.8+ or MariaDB 10.2.3+ is recommended:** search in the 16 non-English languages
  needs functions that older versions do not have, and the faster search uses all its speed-ups
  only on **MariaDB 10.2.3+** with the default `utf8mb4_general_ci` table collation. On MySQL and on other
  collations search finds exactly the same videos; some searches are just slower.
- **A web server:** Apache 2.4 (the `.htaccess` file is included), Nginx, OpenLiteSpeed or IIS
  (the `web.config` file is included). Setup files for these servers and for hosting panels:
  [deployment/README.md](deployment/README.md)
- An empty database and a database user with full rights on it.
- **HTTPS** is strongly recommended. Automatic imports need a public HTTPS address.
- For one-click updates: PHP must be able to write the site's files and reach GitHub over HTTPS. Without that you
  update by hand, or from a package you upload yourself ([UPGRADE.md](UPGRADE.md)).

## Quick install

1. Create an empty database and a database user in your hosting panel.
2. Download the newest release from https://github.com/avdbapi-com/javcms/releases/latest: in its **Assets** list,
   click `javcms-<version>.zip` (for release 1.2.2: `javcms-1.2.2.zip`). Upload the zip to your site folder and
   extract it there. How to check the download: [INSTALL.md](INSTALL.md), "Download JavCMS".
3. Open `https://your-domain/install.php` and follow the steps. Choose which catalogs you will import (AVDB, xvidapi, both or later).
4. Sign in to the admin at `https://your-domain/javcms.php`.
5. Follow the checklist on the first admin page.

Full step-by-step guide: [INSTALL.md](INSTALL.md)

Already running JavCMS? Read "Updates" below and [UPGRADE.md](UPGRADE.md).
What is new: [CHANGELOG.md](CHANGELOG.md)

## Updates

From release 1.2.1, JavCMS updates itself from its GitHub releases. **Admins & backup › Updates** (founder admin
account only) shows the installed and the newest version, the release notes and a check of your site, and installs the
update with one click: with a backup of the replaced files, your settings and your database, a maintenance page for
visitors while the files are replaced, a health check, an automatic rollback when anything fails, and a manual
rollback for 7 days. The admin looks for a new release at most every 12 hours (you can switch that off). Every release
is signed (Ed25519): packages without a valid JavCMS signature, older versions and other products are refused.

- **Sites on 1.2.0** update once with the one-file update bridge `javcms-update.php` from the release page; after that,
  with one click.
- **Sites on 1.1.0** update by hand with the zip.
- **Command line:** `php tools/update.php --check`, `php tools/update.php --apply`, `php tools/update.php --rollback`
  (`php tools/update.php --help` lists every option).

Step by step: [UPGRADE.md](UPGRADE.md)

## All guides

| Guide | What it covers |
|---|---|
| [INSTALL.md](INSTALL.md) | Installing, file permissions, first steps, cron, HTTPS |
| [UPGRADE.md](UPGRADE.md) | Updates: one click from 1.2.1, the update bridge for 1.2.0, by hand from 1.1.0 |
| [CHANGELOG.md](CHANGELOG.md) | Everything that changed |
| [docs/THEMES.md](docs/THEMES.md) | Themes, preview, visitor theme switcher |
| [docs/IMPORT.md](docs/IMPORT.md) | Importing videos from AVDB and xvidapi |
| [docs/HOSTING.md](docs/HOSTING.md) | Which hosting for which catalog size, cron, MySQL settings |
| [docs/ADS.md](docs/ADS.md) | Ads, places, video ads, network code |
| [docs/SEO.md](docs/SEO.md) | Site icon, titles, sitemap, robots.txt, languages |
| [docs/MAINTENANCE.md](docs/MAINTENANCE.md) | Speed & protection, cache, clean-up, search index, database size, backups |
| [docs/SECURITY.md](docs/SECURITY.md) | Keeping your site safe, floods and Cloudflare |
| [deployment/README.md](deployment/README.md) | Server and hosting panel setup (advanced) |

## Where to get help

- Telegram group: https://t.me/+jj6McO-iYfA0MTM1
- Support: https://t.me/avdbapi_support
- GitHub: https://github.com/avdbapi-com/javcms (report bugs here)
- AVDB API documentation: https://avdbapi.com/index.php/label/help.html

When you ask for help, tell us your PHP version, your database version, your web server
and what you see on the screen. Never post your passwords.

## License

From release 1.2.0, JavCMS is under the **JavCMS Community License 1.0** (file `LICENSE`):

- **Share it, don't sell it.** Anyone may use JavCMS for free, also for their own website that earns money (for
  example with ads), and may copy, change and share it.
- Nobody may sell JavCMS: no money or other payment for copies, downloads, changed versions, themes or parts of it,
  installation packages or access behind a paywall, and none for installing, setting up, running, supporting,
  changing or handing over JavCMS for other people, or for hosting plans that come with JavCMS installed (a host whose
  customers install it themselves is fine). Shared copies stay free, under the same license, with every notice kept
  and changes marked, and without money-making code or links (ads, affiliate codes, paid links, miners) of the
  person who shares them. Selling a website that you have really run yourself is allowed, as long as JavCMS on it stays
  free with every notice kept and no price is asked for JavCMS itself; building or setting up JavCMS websites in order
  to sell them is not allowed.
- JavCMS is based on MacCMS 10 by magicblack and contains changes from the MacCMS editions RamCMS (Apache-2.0) and
  ApiiCMS (MIT). MacCMS, ThinkPHP and the other third-party parts keep their own licenses (mostly Apache-2.0, MIT and
  LGPL); `LICENSE` and `NOTICE` list them, `NOTICE.CHANGED-FILES.txt` lists the changed files of the MacCMS release
  and of the bundled editor libraries, CodeMirror and Layui. Releases up to 1.1.0 stay under the Apache License 2.0 (files in them that said
  MIT stay MIT for those copies).
- JavCMS PHP, template, CSS and JavaScript files start with a short comment that names JavCMS, its license, the GitHub
  repository and the live demo (except a few generated or data files, two readable rewrites of third-party jQuery
  plugins and the two small loader files of the bundled sodium_compat code, all named in `NOTICE`). It is an ordinary
  code comment and never appears in your pages.

Official sources: https://github.com/avdbapi-com/javcms (source code and releases), https://javcms.net (live demo,
adult content, 18+), https://avdbapi.com, https://xvidapi.com, https://upload18.org and the Telegram group
https://t.me/+jj6McO-iYfA0MTM1 (Support: https://t.me/avdbapi_support).

Download JavCMS only from its GitHub release page, https://github.com/avdbapi-com/javcms/releases. javcms.net is the
live demo, not a download place, and copies on other websites can be old or changed. JavCMS is free: if somebody asks
you to pay for it, it is not an official download. JavCMS 1.1.0 and older are not offered for download.
