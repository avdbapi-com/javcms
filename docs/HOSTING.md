# Which hosting do you need?

<!-- Measured values of the 1.2.0 verification (2026-10-16/19, test servers with up to 8.07 million videos). -->

JavCMS runs on cheap shared hosting for small and medium sites, and on a small VPS for millions of videos.
What decides the hosting is **how many videos you plan** and **how many title languages you keep**.
This page tells you what to rent before you start, and how to set it up. Every number on this page was measured on
test servers with up to 8 million videos; the section "Where the numbers come from" lists them.

The admin page **Import videos** shows an estimate for your own choice before every import
(see [IMPORT.md](IMPORT.md)). **Speed & protection** shows the row of the table below that matches your site.

## The table

Sizes are database sizes after the import. 1 GB = 1024 MB, as the admin shows them.

| Catalog you plan | Database size | Minimum hosting | MySQL memory (buffer pool) | Import method | Notes |
|---|---|---|---|---|---|
| **up to 50,000 videos** (any source, English) | 0.1-0.2 GB (AVDB with all 16 languages: about 1.1 GB) | shared hosting. A 1 GB database holds about 320,000 xvidapi videos, about 220,000 AVDB videos in English, or about 37,000 AVDB videos with all 16 languages | 128 MB is fine | the admin page: **Whole catalog** or any number; it continues while the site has visitors | cron recommended for the hourly sync |
| **50,000-600,000** (for example AVDB full, or the newest 100,000-500,000 from xvidapi) | 0.1-2.2 GB English; AVDB full with all 16 languages: about 11 GB | shared hosting with a database of 3 GB or more for English; VPS 2 vCPU / 2 GB for all languages | 128-512 MB | the admin page or cron + `tools/import.php` (both import everything; cron is faster) | the search index is needed for first searches under 0.5 s |
| **600,000-2,000,000** | 1.5-4.9 GB English (1.1-3.7 GB with the slim-index tool), plus AVDB translations if you keep them | VPS 2 vCPU / 2 GB, 20 GB disk | 512 MB | the admin page or `tools/import.php` (both import everything); the xvidapi snapshot needs only a few provider requests (14 for 1 million videos) | Cloudflare optional; slim-index tool offered; PHP 8 recommended |
| **2,000,000-7,100,000** (both full catalogs) | English only: about 13.6 GB with the slim-index tool, about 18.2 GB with the default indexes. With all 16 AVDB languages: about 23.0 GB (slim-index tool) to 27.6 GB (default indexes). MariaDB 10.6-10.10: about 1.8 GB more | **VPS 2 vCPU / 4 GB.** Disk: **40 GB** for English with the slim-index tool; **60 GB minimum, 80 GB recommended** with all languages (both with the binary logs switched off for the first import, see below) | 1-1.5 GB (512 MB works: searches measured as fast) | the admin page or `tools/import.php` (both import everything); the first full import takes **about 8 to 8.4 hours** with the xvidapi snapshot (6 to 6.4 hours of import plus 2 hours for the search index) and **about 18-24 hours** with id windows (older xvidapi; see [IMPORT.md](IMPORT.md)) | cron strongly recommended (maintenance and the faster import); slim-index tool recommended; Cloudflare optional; PHP 8 recommended; MySQL 8: `skip-log-bin` for the first big import, then `binlog_expire_logs_seconds=86400` |

How to read it:

- **Languages make AVDB big.** An AVDB video with all 16 translated titles and descriptions needs about 6 times
  the space of an English-only video (22.6 KB instead of 3.8 KB). xvidapi titles have no translations, so xvidapi
  videos stay small (2.6 KB, or 1.9 KB with the slim-index tool).
- **Every size includes the 1.2.0 indexes** that each site gets: the five word indexes of the title, actor, tag,
  category and director filters, the four sort indexes and the term map of the search index (together about 0.27 GB
  per million videos). One million xvidapi videos with the slim-index tool measured 1.84 GB on MySQL 8.0 and 1.83 GB
  on MariaDB 11.4.
- **An import stops cleanly at 80 % of your database limit** (the storage target), so a "1 GB" plan holds about
  0.8 GB of videos. That is already counted in the first row. You can start the whole catalog on any plan: the newest
  videos come first and the import ends at the storage target.
- **The full xvidapi catalog** is 6.6 million videos. The table counts all of them.
- "Slim-index tool": `php tools/db-tune.php --slim-indexes` removes 24 rarely used indexes of the video table.
  The video table gets about a third smaller (1.2 GB instead of 1.8 GB per million xvidapi videos) and imports get
  faster. See [MAINTENANCE.md](MAINTENANCE.md).
- **MariaDB 10.6 to 10.10** keep a bigger search index for now (2.09 GB per million xvidapi videos): add about 1.8 GB
  to the last row (both full catalogs: 15.4 / 20.0 / 24.8 / 29.4 GB instead of 13.6 / 18.2 / 23.0 / 27.6 GB), and
  searches read 2-4 times more from the disk. MariaDB 10.3-10.5, 10.11 and newer, MySQL 5.7 and MySQL 8.0 are not
  affected.
- **The search index holds every language you keep** (the default). With all 16 AVDB languages its table is about
  0.7 GB bigger for both full catalogs (1.9 GB instead of 1.2 GB; counted in the sizes for all 16 languages above), and
  a rebuild of it needs about 8.4 GB of temporary disk space. When you set a database limit and the database uses more
  than half of it, JavCMS keeps English and your site language only. To choose the languages yourself, set for example
  `'ft_langs' => 'en,ja'` in the `search` part of `application/extra/maccms.php`, then run
  `php tools/search-index.php --rebuild`.

## Before you rent: three questions

1. **How many videos?** Up to 50,000: any shared hosting works. Above 600,000: rent a VPS.
2. **Which title languages?** English only, or English plus a few, keeps AVDB small. All 16 languages need
   about 22 KB per AVDB video, and searches over all 16 are slower (see "Expected speed").
3. **Can you add a cron job?** Optional, but recommended: cron (every 5 minutes) imports faster and runs the
   maintenance. Most hosting panels have a "Cron Jobs" page. Without cron, the admin page imports everything too: it
   continues by itself while your site has visitors (on PHP-FPM or LiteSpeed hosting) or while the tab is open.

## Set your database limit

Many hosts limit the database size (cPanel shows it under "MySQL Databases" or "Disk usage").
The first time you open **Import videos**, JavCMS asks **"How big may your database get?"**:

- Enter your limit in GB (for example `1` or `2.5`).
- **I don't know**: JavCMS uses 1 GB when the database runs on another server or no cron was seen,
  and no limit on a VPS with a local database.
- **No limit (my own server)**: JavCMS then only checks the free disk space.

An import stops cleanly when the database reaches 80 % of the limit (the storage target; the estimate shows "Stops at
about N videos" before you start). A running import, the automatic imports and
the search index updates pause before the database reaches 90 % of the limit, or when the free disk drops under
2 GB or under 10 %. The import pauses with a message such as "Database almost full (0.9 of 1 GB)". Your site keeps
working. Change the limit any time with **Change database limit** on the import page, or under **Speed & protection**.

**After an upgrade of a site whose database is already bigger than 1 GB**, answer this question right away (enter
your limit, or **No limit** on your own server): with the 1 GB guess of "I don't know", such a site counts as full.

## Disk space during a big import ("peak disk")

A big import needs more disk **while it runs** than after it ends:

- the new data,
- the **binary logs** (MySQL 8: on by default, row format, kept 30 days; MariaDB and MySQL 5.7: off unless your host
  turned them on). With real video rows a full xvidapi import writes about 52 GB of them on MySQL 8 (8.3 KB per video),
  about 41 GB with MariaDB's default mixed format and about 89 GB with row format plus row annotations
  (`binlog_format=ROW` on MariaDB), whichever way it imports (snapshot or id windows); each rebuild of the search index
  adds about 2.2 GB. They stay on the disk until they expire: with the 1-day setting below, those of a running import
  stay too,
- one copy of the search table while it is rebuilt at the end (measured 2.3-3.3 GB for both full catalogs),
- temporary files of the closing search step: none with MySQL 8.0;
  with MariaDB 11.4, 1.5 GB for 2 million videos.

The estimate shows "Highest disk use while importing" (with the binary-log format your server reports). When the peak
does not fit your free disk, the import stops earlier, at the storage target (JavCMS keeps room for the closing
search-index step plus 2 GB). For both full catalogs the peak is about 19 GB (English, slim indexes) or about 33 GB (all
languages, default indexes) with the binary logs switched off (`skip-log-bin`, below). With the binary logs on (MySQL 8's
default, or the 1-day setting while the import runs) it is about 73 GB (English, slim indexes, MySQL 8's row format).

### MySQL 8 binary logs

MySQL 8 writes every change twice: into the table and into a binary log, kept for 30 days. On a single server
without replication you do not need them. Before the first big import, edit your MySQL configuration
(`/etc/mysql/my.cnf`, `/etc/my.cnf` or the "MySQL configuration" page of your panel), under `[mysqld]`, and switch
them off:

```
skip-log-bin
```

or keep them for 1 day only:

```
binlog_expire_logs_seconds=86400
```

With the 1-day setting the binary logs of a running import stay until a day after they were written: a whole xvidapi
import writes about 52 GB of them (row format), so count them in the peak above.
Restart MySQL after the change. MariaDB has binary logs off by default; on shared hosting your host manages this for you.

## Cron: the right line for your panel

With cron, imports (faster, in bigger steps), the hourly sync and the maintenance tasks run every 5 minutes.
The import page prints the exact line for your site with **Copy**; it looks like this:

```
*/5 * * * * php /home/YOURUSER/public_html/tools/import.php --run --max-minutes=4 >/dev/null 2>&1
```

Replace `/home/YOURUSER/public_html` with the real folder of your site. The command must use **the same PHP version
as your site** (7.4 or newer). If `php` is an older version on your server, use the full path of the right one:

| Panel | Where | PHP program (example for PHP 7.4; use your version) |
|---|---|---|
| **cPanel** | Advanced › Cron Jobs › "Add New Cron Job", Common Settings "Once Per Five Minutes" | `/usr/local/bin/ea-php74` (EasyApache) or `/opt/alt/php74/usr/bin/php` (CloudLinux) |
| **DirectAdmin** | Advanced Features › Cron Jobs | `/usr/local/php74/bin/php`, or `/usr/local/bin/php` |
| **Plesk** | Websites & Domains › Scheduled Tasks › Add Task › "Run a PHP script" | choose the PHP version in the form; script path `httpdocs/tools/import.php`, arguments `--run --max-minutes=4` |
| **Your own VPS** | `crontab -e` as the user that owns the site files | `/usr/bin/php` (check with `php -v`) |

The import page also shows "If "php" is not found, your PHP program may be one of: ..." with the paths it found on
your server, and **Cron last ran** (green when cron ran in the last 24 hours).

To test the line once by hand (SSH), run it without the `*/5 * * * *` part and without `>/dev/null 2>&1`.
It works for up to 4 minutes and stops. Running it again continues where it stopped.

### Sitemap files on a big site

The installer, **Finish upgrade** (Speed & protection) and `php tools/upgrade.php` build the sitemap files right away:
the index first, then the video files, newest first (30 seconds from the admin pages, 2 minutes in `tools/upgrade.php`;
`--sitemap-budget=N` changes that). Housekeeping builds the rest: within minutes with cron or PHP-FPM, one file at a time
from page visits without them. **Above 5 million videos, give housekeeping cron or PHP-FPM:** building every sitemap file
once takes about 4-9 minutes of work with 7 million videos (0.18-0.37 s per file of 5,000 videos), too much for one file
at a time from page visits. Until then a big video file (1,000 videos or more) is answered with a lighter version:
the same addresses and dates, without the video details. To build them all at once over SSH:

```
php tools/housekeeping.php --sitemap-files-only --sitemap-budget=600
```

Run it again until it shows `"left":0`. Add `--scheme=https` (or `--scheme=http`) when your preferred canonical protocol
is "auto" and nobody has opened your sitemap yet: the command line cannot know which protocol your visitors use.

The files are made from the sitemap blocks (one per 5,000 videos). On a site upgraded from 1.1.0, housekeeping or
`php tools/upgrade.php` builds the blocks first; when **Finish upgrade** runs before that, it says "Sitemap files: built
by housekeeping after the sitemap blocks", and housekeeping builds the files once the blocks are there.

Housekeeping also keeps a small rows list per block (`runtime/scale/sitemap_rows/`, about 140 KB per 10,000 videos:
about 100 MB for both full catalogs; none is written while less than 1 GB of disk is free). A video file that was never
built is then answered from its list in a few hundredths of a second, without reading the video table.
`php tools/upgrade.php` writes the lists of blocks built before 1.2.0 for up to 3 minutes (`--sitemap-rows-budget=N`
seconds; `0` leaves them to housekeeping). **Clear cache** does not touch this folder and never needs to.

## What a VPS needs

For 2,000,000 videos or more (VPS 2 vCPU / 4 GB):

- MariaDB 10.3-10.5 or 10.11 and newer, or MySQL 8.0, with `innodb_buffer_pool_size=1G` (1.5 GB when the VPS runs
  nothing else). MariaDB 10.6-10.10 work too, with the bigger search index noted above.
- PHP 8 (PHP 7.4 works, but it built the same video page 4-5 times slower in our tests: 0.23-0.30 s instead of
  0.05-0.06 s).
- PHP-FPM with about 8 workers (`pm.max_children = 8`).
- PHP's default memory limit (128 MB) is enough: housekeeping raises its own limit when it rebuilds the term map of
  the search index (up to about 256 MB from about 3 million videos: 64 MB + 60 MB per million). Where the host keeps
  PHP's limit lower, **Speed & protection** says so; then run `php tools/search-index.php --map` once.
- Cron for `tools/import.php --run` (above) and for the maintenance task (see [MAINTENANCE.md](MAINTENANCE.md)).
- The slim-index tool **before** the first big import.
- Cloudflare is optional. The flood protection works at your server without it (see "Protection" below);
  if you use Cloudflare, set up the origin lock (see [SECURITY.md](SECURITY.md)).

A VPS with 2 GB RAM (`innodb_buffer_pool_size=512M`, 4-6 PHP workers) also runs 7 million videos: the speed
measurements below were made with 512 MB.

## Expected speed

Measured on a 2-core test server with a cold database (nothing in memory before each page) and 7.1 million videos.
A normal VPS disk reads faster than the test disk, so your pages are usually at least as fast.

| What | VPS 2 vCPU, 512 MB-1.5 GB buffer pool | Shared hosting (1 core, 128 MB, 600,000 videos) |
|---|---|---|
| a new search (typical) | 0.2-0.28 s; 95 % of searches within about 1 s (MariaDB 10.6 / 11.4, MySQL 8.0) | 0.28 s, 95 % under 0.75 s (MariaDB 10.3); MySQL 5.7: 0.61 s, 95 % under 1.4 s |
| the same search again (page cache) | under 5 ms | |
| home page after a cache clear | 0.25-0.65 s | 0.34 s (MariaDB 10.3) |
| category page 1 | 0.12-0.2 s | |
| video page and watch page | 0.05-0.23 s (PHP 8) | 0.15-0.44 s |
| cached pages per second | 1,100-1,880 (2 cores, PHP 8) | |
| import, command line | with the xvidapi snapshot: 285-359 new videos per second for each 100,000 (1,500,000 measured; test machine not limited to 2 cores), about 6 to 6.4 hours for 6.6 million; with id windows the provider's pace of one request every 5 seconds makes it about 18 hours (the importer alone on 2 cores: about 425 new videos per second, 2,440 per second for unchanged videos, about 4.3 hours for 6.6 million) | |
| rebuild of the search index | 2 hours for 7.9 million videos (MySQL 8.0), about 3 hours on MariaDB 10.6 | |
| upgrade 1.1.0 to 1.2.0 | | 28-52 s for 524,000 AVDB videos with 16 languages |
| the whole `php tools/upgrade.php` (with the search index backfill, sitemap files and deep-page maps) | about 16-17 min for 525,000 AVDB videos with 16 languages (search index about 13 min, sitemap files 2 min); `--no-search-backfill` / `--no-deep-maps` leave those parts for later | |

With all 16 AVDB languages in the search index (the default, see "How to read it"), new searches measured as fast:
95 % under 0.26-0.36 s with 7.1 million videos. On a site whose videos changed a lot since the last rebuild of the
search index, page 1 of a word that is neither rare nor very common can show fewer than 20 videos; it then continues
with **Older results**.

### Known slower cases (1.2.0)

Measured with 7.1 million videos on the same 2-core test server, cold database (the first visit; the page is cached
afterwards). These apply from size level 3 (1,000,000 published videos or more; Speed & protection › Details shows
your site's level); below it, lists, totals and landing descriptions count as in the first 1.2.0.
Every list stays exact; these pages are only slower, or show less:

- **Actor names and the tags that the term map cannot prove exact** (plural or compound words, tags that are also
  category names; see [MAINTENANCE.md](MAINTENANCE.md), "Search"): page 1 of a common actor about 0.3 s, page 1 of such a
  tag about 0.2 s of database time (0.4 s for the whole page). Page 1 shows "N+ videos" with links to the next page and
  to **Older results**. A very common name then numbers its first 10,000 videos: its pages 2-501 take about 0.75-1.3 s
  on a cold database, a deep one (for example page 501 of "milf") can get the simpler page on its first visit, and page
  501 continues with **Older results**. A rarer name (an actor with 30,000 videos, the category "Big Tits") keeps "N+
  videos" with links to the next page and to **Older results** on every page; its pages far down (page 25 and beyond
  of that actor, page 50 and beyond of "Big Tits") get the simpler page on a cold database, and **Older results**
  reaches every older video. A page number above 501 is never linked.
- **A category-name filter that matches a big part of the catalog** (`/search/?class=...`, for example "Big Tits"):
  0.6-0.8 s on its first visit, with a full first page; rare names 0.17-0.21 s.
- **A tag, actor or category name with a very short word beside a longer one** (a word under 3 letters, for example
  "Yuvin B"): a full first page in up to about 1.4 s on its first visit (the page reads the name's matches once). Names
  **written in Chinese, Japanese or Korean** take up to about 2.3 s, and a director name in these scripts can get the
  simpler page on a big site: the exact text check has no word index for such names. A later release adds them to the
  term map of the search index.
- **A list of several long names** in one filter (names separated by commas, longer together than the search length
  setting; mostly old links): its sorted and paged links cost 0.5-0.9 s each, sorted by score or likes the simpler
  page. Page 1 is cached (0.12 s).
- **The first visit of a tag or actor whose videos are all old**: a full first page in about 1.7 s, its later pages up
  to page 501 too (1.7-1.8 s each on a cold database, about 0.3 s once the database has those rows in memory).
- **A name without a word index**: a name made only of words under 3 letters (for example the category "VR"), a code
  or name with "_", "%" or an apostrophe in it, or a word that more than 50,000 videos hold. Page 1 reads the plain
  text match for up to about 2.5 s. On a cold database it is full when the name's videos lie within about the newest
  100,000; deeper, it shows fewer videos, or none, with **Older results**, which reads on from where the page stopped.
  The category "VR" (its first videos about 90,000 deep): 3 of 20 in 3.2 s on the first visit, all 20 in 2.8 s on the
  next one (a short first page is kept only a minute). Every video shown is a matching one.
- **A search engine on a big site right after a restart** (cold database): Googlebot alone at 10 pages per second for
  one minute got 595 of 595 pages with HTTP 200, 95 % of them within 0.11-0.13 s, every video and watch page and every
  list page up to page 50 in full. 0-3 heavy pages per run, all in its first 15 seconds (filter pages, a category page
  after page 50), came as the lighter page while two slow filter pages held the two places a search engine has for
  rendering pages: HTTP 200 without "noindex", with the newest videos and a "Try again" link. The search engine gets
  the full page on a later visit.
- **Actor pages and the tag pages of the text match with very common names** (every word of the name is in more than
  10,000 videos, for example the tag "Creampie"): their description no longer starts with the number of videos
  (1.1.0 started it with the exact number), because an exact count of such a name would read too many rows on its
  first visit. The page stays indexable and its list exact. Single-word tags that the term map proves exact keep
  the number: "10000+ videos tagged ..." above 10,000 videos, else the exact count. On a cold first visit, a less
  common name whose count runs out of time shows its description without the number for up to 10 minutes.

## Protection

The limits run at your server; no Cloudflare or other service is needed. All of them are on by default with high
values; **Speed & protection › Protection against floods** shows them and lets you raise or lower them
([SECURITY.md](SECURITY.md), section 10). Measured on the 2-core test server:

| What | Limit (default) | Measured |
|---|---|---|
| searches, filtered lists and deep list pages per visitor | 300 per minute per address (IPv4) or per /64 network (IPv6) | over the limit: "429 Too many requests", answered before the site loads (under 5 ms; measured with the earlier 30-per-minute setting, the check is the same) |
| other pages that are not cached (video pages and so on) per visitor | 2,400 per minute | then 429 |
| cached pages | no limit | a visitor over the limits still gets every cached page |
| checked search engines (Googlebot, Bingbot, YandexBot, DuckDuckBot, Applebot) | never limited or turned away, also in Under attack mode | 45 searches in one minute from a listed Googlebot address: 0 refused, also in Under attack mode; a fake "Googlebot" from another address is refused like any visitor (test site, PHP 8.5 and 7.4); Googlebot alone at 10 pages per second right after a restart (7.1 million videos): 595 of 595 pages with HTTP 200, 0-3 of them the lighter page (see "Known slower cases") |
| Under attack mode (off by default) | 30 / 240 per minute, half the search slots, query time limit at most 3 s | the 31st search in a minute gets 429; with the defaults 45 searches in a minute are all answered (test site, PHP 8.5 and 7.4) |
| a flood from 1,000 addresses (searches) | busy slots: extra searches get "503, try again" with a waiting time | a normal visitor's video pages: 95 % under 0.4-0.65 s, never blocked |
| a flood from random addresses | as above | normal visitors' pages: half under 0.42 s, 95 % under 0.62 s (PHP 8, 2 cores) |
| a hidden or deleted video | | 404 on the next request, also from the cache and during a flood |
| database or disk almost full | imports, search updates and view counters pause | tested at 92 % of the limit, at under 2 GB or 10 % free disk, and with "disk full" errors |

On a single core a heavy flood is too much for any PHP site: in our test normal visitors waited up to 4-16 seconds.
So a VPS needs 2 vCPU or more (the minimum in the table above). Behind a proxy or load balancer, set the proxy in
**Speed & protection**
(the page warns when every visitor seems to come from one address), otherwise the per-visitor limits cannot tell
your visitors apart.

## One-click updates

From 1.2.1, **Admins & backup › Updates** updates JavCMS from its GitHub releases. For that your hosting needs:

- outgoing HTTPS connections from PHP to `api.github.com`, `github.com` and GitHub's release download hosts
  (`release-assets.githubusercontent.com`, `objects.githubusercontent.com`); nothing else is contacted. Without them,
  upload the release files into `runtime/update/inbox/` ([../UPGRADE.md](../UPGRADE.md), "Without an outgoing
  connection");
- write permission for PHP on the folders of the release files (on most shared hosting PHP runs as your own user);
- free disk space for the download, the backup and the new files (the Review checks it before anything changes);
- `runtime/` as a real folder of the site on a local disk (not a link to another folder, not a network mount without
  file locks);
- where the web server's OPcache never re-reads changed files (`opcache.validate_timestamps=0`): permission for the site
  to reset OPcache (`opcache_reset` not in `disable_functions`, `opcache.restrict_api` not pointing elsewhere).

Short PHP time limits, `open_basedir`, LiteSpeed and IIS work: every part of an update runs in short steps that
continue where the last one stopped. Without these conditions you update by hand. Details:
[../UPGRADE.md](../UPGRADE.md), "Hosting notes for updates".

## Where the numbers come from

All sizes were measured on test servers with up to 8.07 million videos, after a real import order (random index
inserts), without "optimize table", including the search index files the database does not list. Per unit:

| What | Measured |
|---|---|
| 1 million xvidapi videos: video table with the default indexes / with the slim-index tool | 1,869 MB / 1,226 MB |
| search index per 1 million videos (English) / import records per 1 million | 320 MB / 72 MB |
| the 1.2.0 indexes per 1 million videos (MySQL 8.0): five word indexes (FULLTEXT) / their FTS_DOC_ID column / four sort indexes / term map of the search index | 125.5 MB / 27 MB / 86.2 MB / 32.4 MB (271 MB together) |
| 1 million xvidapi videos with everything above, slim-index tool: MySQL 8.0 / MariaDB 11.4 / MariaDB 10.6-10.10 | 1,889 MB = 1.84 GB / 1.83 GB / 2.09 GB |
| search table with every language at 7.1 million videos / English only | 1,913 MB / 1,224 MB |
| 524,000 AVDB videos, English: default indexes / slim-index tool | 1,581 MB / 1,139 MB |
| their translations, 16 languages (8.4 million rows) / extra search index for 16 languages | 8,450 MB / 1,180 MB |
| MySQL 8 binary logs per 1 million new videos (lean test rows, row format) / per rebuild of the search index | 4,158 MB / 2,245 MB |
| binary log per imported video with real-sized rows (2.28 KB of provider data; MariaDB 10.3, 10,000 videos per run): row format + row annotations / row format only (MySQL 8's default) / mixed format (MariaDB's default) | 14.15 KB / 8.39 KB / 6.54 KB (the import estimate uses 14.18 / 8.31 / 6.48 KB for xvidapi rows) |
| shared-hosting test site, 600,000 videos in English (whole database folder) / at its peak | 1.7 GB / 2.4 GB |
| 524,000 AVDB videos with 16 languages (whole database folder) | 11.0 GB |
| whole xvidapi catalog through the snapshot, time (test copy of the snapshot, command line, MariaDB 11.4, slim indexes, binary log on, into a site with 520,956 videos; test machine not limited to 2 cores) | 1,000,000 new videos in 3,232 s = 309 per second (each 100,000 at 285-351 per second, 1.5 GB buffer pool); 500,000 more in 1,484 s = 337 per second (307-359, 512 MB buffer pool); 14 provider requests for the 1 million; no video imported twice |
| the same, disk (test rows about 6 times leaner than real ones, so these sizes are not used above) | database folder 2.3 GB before, 3.6 GB and 4.0 GB after the two runs (2,020,956 videos), highest 4.2 GB / 4.6 GB during them (binary logs above 1.5 GB deleted while they ran); closing search step (2,015,194 videos, 499 s): highest 4.9 GB plus 1.5 GB of temporary files |
| the same, binary log (row format with row annotations, test rows) | 7.2 KB per imported video (6.9 GB per million): the test-row value of the import estimate |

| Number in this guide | Calculation |
|---|---|
| 2.6 KB / 1.9 KB per xvidapi video | (1,869 + 320 + 72 + 271) MB / 1,000,000 = 2.59 KB; (1,226 + 320 + 72 + 271) MB / 1,000,000 = 1.93 KB |
| 3.8 KB / 22.6 KB per AVDB video | 1,581 MB / 524,000 + 0.68 KB = 3.77 KB; + (8,450 + 1,180) MB / 524,000 = 22.59 KB |
| 0.1-0.2 GB for 50,000 | 50,000 x 2.59 KB = 0.12 GB; 50,000 x 3.77 KB = 0.18 GB |
| about 1.1 GB for 50,000 AVDB, 16 languages | 50,000 x 22.59 KB = 1.08 GB |
| about 320,000 / 220,000 / 37,000 in 1 GB | 0.8 GB / 2.59 KB, / 3.77 KB, / 22.59 KB |
| 0.1-2.2 GB English (second row) | 50,000 x 2.59 KB = 0.12 GB to 600,000 x 3.77 KB = 2.16 GB |
| database of 3 GB or more | 2.16 GB / 0.8 = 2.7 GB |
| AVDB full, 16 languages: about 11 GB | 524,471 x 22.59 KB = 11.3 GB (the whole test database folder without the 1.2.0 indexes: 11.0 GB) |
| 1.5-4.9 GB (third row) | 600,000 x 2.59 KB = 1.48 GB to 2,000,000 x 2.59 KB = 4.95 GB; slim 1.11-3.69 GB |
| about 13.6 GB / about 18.2 GB (both catalogs, English) | 6,596,643 xvidapi x 1,226 (slim) or 1,869 (default) MB per million + AVDB 1,139 or 1,581 MB + 7,120,643 videos x (320 + 72 + 271) MB per million: 13.6 GB with slim indexes, 18.2 GB with the default indexes |
| about 23.0-27.6 GB (both catalogs, 16 languages) | the same + 8,450 + 1,180 MB: 23.0 GB slim, 27.6 GB default |
| MariaDB 10.6-10.10: about 1.8 GB more | 7,120,643 videos x (2,142 - 1,889) MB per million = 1.8 GB: 15.4 / 20.0 / 24.8 / 29.4 GB |
| about 0.7 GB more search index with all languages | 1,913 MB - 1,224 MB = 0.7 GB |
| about 52 / 41 / 89 GB of binary logs | 6,596,643 x 8.31 KB = 52.3 GB (row format); x 6.48 KB = 40.8 GB (mixed format); x 14.18 KB = 89.2 GB (row format + annotations) |
| peak about 19 GB / 33 GB | 13.6 GB + 3.3 GB search-table copy + 2 GB room = 19 GB; 27.6 + 3.3 + 2 = 33 GB |
| about 73 GB peak with the binary logs on | 13.6 + 52.3 (row format) + 3.3 search-table copy + 2.2 rebuild binary log + 2 GB room = 73 GB |
| about 18-24 hours for the first full import | 6,596,643 / 500 per request = 13,194 requests x 5 s = 18.3 h, plus 2 h for the search index |
| about 6 to 6.4 hours with the snapshot (8 to 8.4 hours with the search index) | 6,596,643 / 307 per second (slowest 100,000, 512 MB) = 6.0 h; / 285 per second (slowest 100,000, 1.5 GB) = 6.4 h; plus 2 h for the search index |

Not measured yet: imports with complete rows (all optional fields; the snapshot runs used test rows about 6 times
leaner than real ones; the binary logs above are scaled from 10,000 real-sized videos per run), the full 6.6 million
import in one run
(1.5 million were imported through the snapshot in two runs: the snapshot times above are scaled from them, the
id-window peak from 1 million), the snapshot import on a 2-core VPS, the pace of the real xvidapi server, and the real
xvidapi export files (the snapshot runs used a test copy in the published format).
