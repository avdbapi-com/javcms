<!--
  Repository page of JavCMS (shown by GitHub from .github/README.md).
  The package README for people who download the zip is /README.md and stays unchanged.
  Art in /docs/images: made for JavCMS by AvdbAPI.com, under the JavCMS Community License 1.0 like the rest of docs/
  (see docs/images/CREDITS.md).
-->

<p align="center">
<a href="#quick-install"><picture>  <source media="(prefers-color-scheme: dark)" srcset="/docs/images/javcms-hero-dark.svg">  <source media="(prefers-color-scheme: light)" srcset="/docs/images/javcms-hero-light.svg">  <img alt="JavCMS: the free, source-available video CMS. 17 themes, 17 languages, ads manager, SEO suite, imports from AVDB API and xvidapi." src="/docs/images/javcms-hero-dark.svg" width="100%"></picture></a>
</p>

<h3 align="center">Your own video site, filled automatically. Free, source-available, built for millions of videos.</h3>

<p align="center">
<a href="https://github.com/avdbapi-com/javcms/releases/latest"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/badge-download-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/badge-download-light.svg"><img alt="Download the latest JavCMS release" src="/docs/images/badge-download-dark.svg" height="30"></picture></a> <a href="https://javcms.net"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/badge-demo-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/badge-demo-light.svg"><img alt="Live demo: javcms.net (adult content, 18+)" src="/docs/images/badge-demo-dark.svg" height="30"></picture></a> <a href="#license"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/badge-license-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/badge-license-light.svg"><img alt="License: JavCMS Community License 1.0 (free to use and share, never sold)" src="/docs/images/badge-license-dark.svg" height="30"></picture></a> <a href="/INSTALL.md"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/badge-php-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/badge-php-light.svg"><img alt="PHP 7.4 to 8.5" src="/docs/images/badge-php-dark.svg" height="30"></picture></a> <a href="/docs/HOSTING.md"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/badge-db-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/badge-db-light.svg"><img alt="MySQL 5.6+ or MariaDB 10.1+" src="/docs/images/badge-db-dark.svg" height="30"></picture></a> <a href="#17-themes"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/badge-themes-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/badge-themes-light.svg"><img alt="17 themes, dark and light" src="/docs/images/badge-themes-dark.svg" height="30"></picture></a> <a href="https://t.me/avdbapi_support"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/badge-telegram-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/badge-telegram-light.svg"><img alt="Telegram support: @avdbapi_support" src="/docs/images/badge-telegram-dark.svg" height="30"></picture></a>
</p>

<p align="center"><a href="#quick-install"><b>Quick install</b></a> · <a href="#updating">Updating</a> · <a href="/UPGRADE.md">Upgrade from 1.1.0</a> · <a href="https://help.avdbapi.com/documentation/installation-guide-for-javcms/">Install guide</a> · <a href="/CHANGELOG.md">What is new</a></p>

<p align="center">Try it live: <a href="https://javcms.net">javcms.net</a> - live demo with adult content, 18+ only. Pick any theme with the palette button. Ads are off on the demo; the <b>Demo</b> button turns them on for your browser.</p>

JavCMS is a free, source-available website system for video sites. You install it on your own hosting, and it fills your site with videos from the AVDB API and the xvidapi API, in a few clicks or fully automatically, from a few thousand videos up to millions. It is based on MacCMS 10 (ThinkPHP 5.0).

<!-- screenshots -->
<p align="center"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/screenshots/javcms-thm-javcms-modern-hd.webp"><source media="(prefers-color-scheme: light)" srcset="/docs/images/screenshots/javcms-thm-javcms-modern-hl.webp"><img alt="JavCMS with its default theme, JAVCMS Modern: the home page of a demo site on a computer, in the coral gallery design, with categories, popular videos and the latest videos" src="/docs/images/screenshots/javcms-thm-javcms-modern-hd.webp" width="100%"></picture><br><sub>The default theme, JAVCMS Modern, on a demo site (dark and light follow your GitHub theme)</sub></p>

<table>
<tr><td width="50%" valign="top"><img alt="Demo site in ad preview mode, Your home page in preview mode with example ads drawn in their places and the Preview panel" src="/docs/images/screenshots/javcms-ads-02.webp" width="100%"><br><sub>Ads manager: preview your own page with example ads<br>1 example ads in their places, 2 preview panel, 3 exit preview</sub></td><td width="50%" valign="top"><img alt="Demo site in ad preview mode, Watch page with a pre-roll example playing and 'Skip ad in 5...'" src="/docs/images/screenshots/javcms-ads-09.webp" width="100%"><br><sub>Video pre-roll with a Skip button<br>1 ad label and countdown, 2 Skip ad</sub></td></tr>
<tr><td width="50%" valign="top"><img alt="JavCMS admin, Speed &amp; protection, Status lines Search index, Counts and list indexes, Protection, Database space, Cron, Deep pages, all Good, with their buttons" src="/docs/images/screenshots/javcms-spd-01.webp" width="100%"><br><sub>Speed &amp; protection: every check in one place<br>1 search index, 2 counts and list indexes, 3 protection, 4 database space, 5 cron, 6 deep pages</sub></td><td width="50%" valign="top"><img alt="JavCMS admin, Search engines: the checklist card with done and open checks and their Fix links, on the demo site" src="/docs/images/screenshots/javcms-seo-01.webp" width="100%"><br><sub>Search engine checklist with Fix links<br>1 checks done, 2 Fix link</sub></td></tr>
<tr><td colspan="2" valign="top"><img alt="JavCMS admin, Import videos, Choices list with 'Whole catalog (recommended)' selected, xvidapi tab, Choose what to import: Whole catalog" src="/docs/images/screenshots/javcms-imp-08.webp" width="100%"><br><sub>Import videos: the whole xvidapi catalog is the default<br>1 whole catalog, 2 newest videos, 3 your own number, 4 one category</sub></td></tr>
</table>

<sub>Screenshots of JavCMS 1.2.0 with demo data; the numbers mark what each caption explains. More in the [help center](https://help.avdbapi.com).</sub>
<!-- /screenshots -->

## Features

<table>
<tr>
<td width="50%" valign="top"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/icon-themes-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/icon-themes-light.svg"><img src="/docs/images/icon-themes-dark.svg" width="40" height="40" alt=""></picture><br><b>17 themes, dark and light</b><br>Every theme works on phones, tablets and computers, starts dark and has a light mode. Visitors can pick a theme with the theme switcher, visible on every page by default.</td>
<td width="50%" valign="top"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/icon-ads-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/icon-ads-light.svg"><img src="/docs/images/icon-ads-dark.svg" width="40" height="40" alt=""></picture><br><b>Ads manager with video pre-roll</b><br>18 places on your pages, 58 example ads in 19 formats, pre-roll with a Skip button, VAST tags, schedules and simple stats. With the main switch off, pages carry no ad code.</td>
</tr>
<tr>
<td width="50%" valign="top"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/icon-seo-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/icon-seo-light.svg"><img src="/docs/images/icon-seo-dark.svg" width="40" height="40" alt=""></picture><br><b>SEO suite</b><br>Sitemaps per language, robots.txt, clean addresses, canonical and hreflang links, structured data, IndexNow and a live Google preview for titles and descriptions.</td>
<td width="50%" valign="top"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/icon-import-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/icon-import-light.svg"><img src="/docs/images/icon-import-dark.svg" width="40" height="40" alt=""></picture><br><b>Imports from AVDB API and xvidapi</b><br>Built in, nothing to configure: about 524,000 videos from AVDB with titles in 17 languages and about 6.6 million from xvidapi. Automatic imports every 15 minutes to once a day, with undo.</td>
</tr>
<tr>
<td width="50%" valign="top"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/icon-speed-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/icon-speed-light.svg"><img src="/docs/images/icon-speed-dark.svg" width="40" height="40" alt=""></picture><br><b>Fast with millions of videos</b><br>A page cache answers in about 2 ms and a search index keeps first searches well under a second. Tested with 7.1 million videos on a 2-core server; see the <a href="#big-catalogs">measured limits</a>.</td>
<td width="50%" valign="top"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/icon-shield-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/icon-shield-light.svg"><img src="/docs/images/icon-shield-dark.svg" width="40" height="40" alt=""></picture><br><b>Protection that never blocks search engines</b><br>Fair limits per visitor network, busy slots and query time limits keep floods out. Checked Googlebot, Bingbot, YandexBot, DuckDuckBot and Applebot are never limited.</td>
</tr>
<tr>
<td width="50%" valign="top"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/icon-languages-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/icon-languages-light.svg"><img src="/docs/images/icon-languages-dark.svg" width="40" height="40" alt=""></picture><br><b>Admin in 4 languages, site in 17</b><br>The admin speaks English, Vietnamese, Simplified and Traditional Chinese. Visitors get a language menu with 17 languages in every theme.</td>
<td width="50%" valign="top"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/icon-admin-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/icon-admin-light.svg"><img src="/docs/images/icon-admin-dark.svg" width="40" height="40" alt=""></picture><br><b>Made for beginners</b><br>A setup checklist on the first page, a search box (press <code>/</code>), one-click backups, a security scan and admin accounts with exact rights.</td>
</tr>
<tr>
<td colspan="2" valign="top"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/icon-update-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/icon-update-light.svg"><img src="/docs/images/icon-update-dark.svg" width="40" height="40" alt=""></picture><br><b>One-click updates from GitHub (1.2.1 and newer)</b><br><b>Admins &amp; backup › Updates</b> installs each new release with one click: signed releases only, a backup of the replaced files, settings and database first, a maintenance page while files are swapped, a health check, an automatic rollback if anything fails and a manual rollback for 7 days. Sites on 1.2.0 move up once with the update bridge (<a href="#updating">Updating</a>).</td>
</tr>
</table>

## 17 themes

<p align="center"><picture>  <source media="(prefers-color-scheme: dark)" srcset="/docs/images/demo-themes-dark.svg">  <source media="(prefers-color-scheme: light)" srcset="/docs/images/demo-themes-light.svg">  <img alt="JavCMS theme switcher: one site layout shown in the real accent colour of each of the 17 themes, with the theme folder name" src="/docs/images/demo-themes-dark.svg" width="100%"></picture></p>

Every theme starts in dark mode, has a light mode and a language menu with 17 languages. Pick one in **Site & theme › Themes**, or let visitors choose with the theme switcher ([docs/THEMES.md](/docs/THEMES.md)).

| Folder | Theme | Modern edition |
|---|---|---|
| `javcms-modern` | JAVCMS Modern: Coral gallery design, the default | |
| `jav` | Classic: Warm black gradient, yellow highlights | `jav-modern` Modern: Indigo gallery, navy panels, aurora hero |
| `ember` | Ember: Black and orange tube design | `ember-modern` Ember Thermal: Thermal-camera catalogue, heat-coded cards |
| `onyx` | Onyx: Near-black and orange, coverflow banner | `onyx-modern` Onyx Grid: Bold ruled index, 1-bit dither effect |
| `velvet` | Velvet: Navy and rose-red night design | `velvet-modern` Velvet Premiere: Premiere-night cinema, velvet curtains |
| `honey` | Honey: Amber header, rounded video cards | `honey-modern` Honey Nectar: Liquid gold, honey-filled hexagons |
| `neon` | Neon: Dark hot-pink app design | `neon-modern` Neon Nocturne: Rain on neon glass, split-flap board |
| `crimson` | Crimson: Red-bar video app design | `crimson-modern` Crimson Ink: Two-colour manga print, ink panels |
| `topaz` | Topaz: Midnight-navy glass with gold accents | `topaz-modern` Topaz Prism: Crystal cards, gem-coloured categories |

## Quick install

**What you need:** PHP 7.4 to 8.5 (PDO MySQL, mbstring, cURL, GD, XML, ZIP, fileinfo and OpenSSL; the installer checks them), MySQL 5.6+ or MariaDB 10.1+ (MySQL 5.7.8+ or MariaDB 10.2.3+ recommended), Apache 2.4, Nginx, OpenLiteSpeed or IIS, and an empty database with a user that has full rights on it. HTTPS is strongly recommended; automatic imports need a public HTTPS address.

1. Create an empty database and a database user in your hosting panel.
2. Download the [latest release](https://github.com/avdbapi-com/javcms/releases/latest): in its **Assets** list, click `javcms-<version>.zip`. Upload the zip to your site folder and extract it there. How to check the download: [INSTALL.md](/INSTALL.md#download-javcms).
3. Open `https://your-domain/install.php` and follow the steps. Choose the catalogs you will import: AVDB, xvidapi, both or later.
4. Sign in to the admin at `https://your-domain/javcms.php`.
5. Follow the checklist on the first admin page.

Big catalogs import faster from the command line or cron, and every import continues where it stopped:

```bash
# size, time and storage target of the whole AVDB catalog
php tools/import.php --estimate --provider=avdb --scope=full
# create the import job, then run it (or let cron run it every 5 minutes)
php tools/import.php --start --provider=avdb --scope=full
php tools/import.php --run --max-minutes=4
```

Step by step, with file permissions, cron and HTTPS: [INSTALL.md](/INSTALL.md). Which hosting for which catalog: [docs/HOSTING.md](/docs/HOSTING.md).

## Updating

- **1.2.1 and newer:** open **Admins & backup › Updates** with the founder admin account (the first account of your site), click **Review and update** (**Check now** first if the new release is not shown yet), then **Update now**. Updates come only from the releases of this repository and must carry the JavCMS release signature; a backup comes first, a failed update rolls back by itself, and you can roll back to the previous version for 7 days. Over SSH: `php tools/update.php --check`, then `php tools/update.php --apply`.
- **1.2.0:** update once with the update bridge. Download `javcms-update.php` from the [latest release](https://github.com/avdbapi-com/javcms/releases/latest) (compare its SHA-256 with `javcms-update.php.sha256`), upload it into your site folder as `install.php`, open `https://your-domain/install.php` while you are signed in to the admin as the founder, and click **Check for updates**, **Check this site** and **Update now**. Step by step: [Update JavCMS 1.2.0 to 1.2.1 (update bridge)](https://help.avdbapi.com/documentation/javcms-update-bridge/).
- **1.1.0:** [upgrade by hand](#upgrade-from-110).

Everything else (the command line, sites without an outgoing connection, an update that cannot finish): [UPGRADE.md](/UPGRADE.md) and the help guide [One-click updates](https://help.avdbapi.com/documentation/javcms-one-click-updates/).

## Upgrade from 1.1.0

Plan about 30 minutes. Your videos, members, settings and uploads stay; nothing in your data is rewritten.

1. Back up the database (**Admins & backup › Backup & restore**, then **Download**) and your site files.
2. Pause automatic imports and any running import.
3. Download the new zip from the [latest release](https://github.com/avdbapi-com/javcms/releases/latest), extract it on your computer and delete `application/database.php`, `application/route.php` and every file in `application/extra/` except `version.php` from the extracted files.
4. Upload the rest and replace the existing files.
5. Sign in, click **Clear cache**, reload with Ctrl+F5, and purge your CDN or OPcache if you use one.
6. Open **Admins & backup › Speed & protection**, click **Finish upgrade**, then **Build search index** (or run `php tools/upgrade.php`).
7. Resume your imports and check your pages, search, `sitemap.xml` and `robots.txt`.

JAVCMS Classic (`template/javcms`) is not part of 1.2.0 or 1.2.1: if your site uses it, switch to JAVCMS Modern or another theme. All details, also for edited theme files and going back: [UPGRADE.md](/UPGRADE.md).

## Big catalogs

Every number below was measured for the 1.2.0 release on test servers with up to 8 million videos ([docs/HOSTING.md](/docs/HOSTING.md)).

| Catalog you plan | Minimum hosting | Database size (English titles) |
|---|---|---|
| up to 50,000 videos | shared hosting | 0.1-0.2 GB |
| 50,000-600,000 | shared hosting with a 3 GB+ database (English), or a VPS with 2 vCPU / 2 GB for all languages | 0.1-2.2 GB |
| 600,000-2,000,000 | VPS 2 vCPU / 2 GB, 20 GB disk | 1.5-4.9 GB |
| 2,000,000-7,100,000 (both full catalogs) | VPS 2 vCPU / 4 GB, 40 GB disk (60-80 GB with all 16 AVDB languages) | about 13.6-18.2 GB |

With 7.1 million videos on a 2-core test server (cold database), a typical new search took 0.2-0.28 s, the same search again under 5 ms, and video pages 0.05-0.23 s on PHP 8. The limits: the first import of both full catalogs takes about 8 hours, cron is strongly recommended above 2 million videos, and a few rare filter pages stay slower; [docs/HOSTING.md](/docs/HOSTING.md) lists them under "Known slower cases".

## Documentation

| Guide | What it covers |
|---|---|
| [INSTALL.md](/INSTALL.md) | Installing, file permissions, first steps, cron, HTTPS |
| [UPGRADE.md](/UPGRADE.md) | One-click updates (1.2.1 and newer), the update bridge for 1.2.0, moving from 1.1.0 |
| [CHANGELOG.md](/CHANGELOG.md) | Everything that changed |
| [docs/THEMES.md](/docs/THEMES.md) | Themes, preview, visitor theme switcher |
| [docs/IMPORT.md](/docs/IMPORT.md) | Importing videos from AVDB and xvidapi |
| [docs/HOSTING.md](/docs/HOSTING.md) | Which hosting for which catalog size, cron, MySQL settings |
| [docs/ADS.md](/docs/ADS.md) | Ads, places, video ads, network code |
| [docs/SEO.md](/docs/SEO.md) | Site icon, titles, sitemap, robots.txt, languages |
| [docs/MAINTENANCE.md](/docs/MAINTENANCE.md) | Speed & protection, cache, clean-up, search index, backups |
| [docs/SECURITY.md](/docs/SECURITY.md) | Keeping your site safe, floods and Cloudflare |
| [deployment/README.md](/deployment/README.md) | Server and hosting panel setup (advanced) |

Guides with screenshots are in the help center: [help.avdbapi.com](https://help.avdbapi.com).

## License

From release 1.2.0, JavCMS is under the **JavCMS Community License 1.0** ([LICENSE](/LICENSE)). In plain words: **share it, don't sell it.**

- **Use it for free**, also for your own website that earns money, for example with ads.
- **Copy it, change it and share it.** Shared copies stay free and under the same license, keep every notice, mark their changes and carry no money-making code or links of the person who shares them.
- **Never sell it.** No payment for copies, downloads, changed versions, themes or parts, and none for installing, setting up, running, supporting or changing JavCMS for other people, or for hosting plans that come with JavCMS installed.
- MacCMS, ThinkPHP and the other third-party parts keep their own licenses (mostly Apache-2.0, MIT and LGPL): see [LICENSE](/LICENSE) and [NOTICE](/NOTICE). Releases up to 1.1.0 stay under the Apache License 2.0.

The license text is written in plain English and is the one that counts; this summary is not legal advice.

## Support and community

- **Questions and setup help:** the [help center](https://help.avdbapi.com) (start with the [install guide](https://help.avdbapi.com/documentation/installation-guide-for-javcms/)), the JavCMS [Telegram group](https://t.me/+jj6McO-iYfA0MTM1) and Telegram support [@avdbapi_support](https://t.me/avdbapi_support).
- **Bugs:** [open an issue](https://github.com/avdbapi-com/javcms/issues/new/choose) with your JavCMS, PHP, database and web server versions and what you see. Never post passwords.
- **Security problems:** please report them privately, see [SECURITY.md](/.github/SECURITY.md).
- **Contributing:** read [CONTRIBUTING.md](/.github/CONTRIBUTING.md) and our [Code of Conduct](/.github/CODE_OF_CONDUCT.md).
- **New releases:** watch this repository (Watch › Custom › Releases). Sites on 1.2.1 or newer also see them in the admin (**Admins & backup › Updates**).

<div align="center">

If JavCMS saves you time, a star helps other site owners find it.

<a href="https://github.com/avdbapi-com/javcms"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/badge-star-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/badge-star-light.svg"><img alt="Star JavCMS on GitHub" src="/docs/images/badge-star-dark.svg" height="30"></picture></a> <a href="https://twitter.com/intent/tweet?text=JavCMS%3A%20a%20free%2C%20source-available%20video%20CMS%20with%2017%20themes%2C%2017%20languages%2C%20ads%20and%20SEO%20built%20in.&amp;url=https%3A%2F%2Fgithub.com%2Favdbapi-com%2Fjavcms"><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/badge-share-x-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/badge-share-x-light.svg"><img alt="Share JavCMS on X" src="/docs/images/badge-share-x-dark.svg" height="30"></picture></a> <a href="https://t.me/share/url?url=https%3A%2F%2Fgithub.com%2Favdbapi-com%2Fjavcms&amp;text=JavCMS%3A%20a%20free%2C%20source-available%20video%20CMS%20with%2017%20themes%2C%2017%20languages%2C%20ads%20and%20SEO%20built%20in."><picture><source media="(prefers-color-scheme: dark)" srcset="/docs/images/badge-share-telegram-dark.svg"><source media="(prefers-color-scheme: light)" srcset="/docs/images/badge-share-telegram-light.svg"><img alt="Share JavCMS on Telegram" src="/docs/images/badge-share-telegram-dark.svg" height="30"></picture></a>

</div>

<p align="center"><sub>Share it, don't sell it: JavCMS is free to use, change and share under the JavCMS Community License 1.0.<br>JavCMS is based on MacCMS 10 by magicblack.</sub></p>
