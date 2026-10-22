# Maintenance

JavCMS looks after itself. This guide explains what it does on its own,
and the few things you should do yourself: backups, a cron job on bigger sites, and keeping an eye on the database size.

## Speed & protection: everything at a glance

**Admins & backup › Speed & protection** shows one line per area, each with a coloured state and one button:

| Line | What it tells you | Button |
|---|---|---|
| **Search index** | "Ready: N videos can be found", or that it is not built yet | **Build search index** / **Rebuild search index** (on very big sites: the command to run) |
| **Counts and list indexes** | when the category counts were last updated; whether the upgrade is finished | **Finish upgrade (about N min)** |
| **Protection** | page cache and limits per visitor network on, cache hits | **Clear cache**, **Trust this proxy**, the **Cloudflare Tunnel switch** |
| **Database space** | "X used of your Y limit" | **Set database limit** |
| **Cron** | when cron last ran | the cron lines to copy |

Red notices tell you what to do: "Build the search index", "Search order is approximate after your big import",
"Set up cron", "All visitors appear to come from <address>: set the proxy header" (see [SECURITY.md](SECURITY.md)),
"Database almost full", "File locks are not supported here".

**Details** shows the size level of your site and its limits, the page cache size, refused pages per hour and the
5 busiest visitor networks, the search details, the Cloudflare rules to copy and the hosting row for your catalog size.
A value that has not been measured yet reads "not reported yet".

## The cache

JavCMS keeps finished pages for a short time, so most visits cost almost nothing, even on cheap hosting:

- the **page cache** (`runtime/edge/`): complete pages for visitors who are not signed in, kept 5 minutes.
  It has a size limit (256 MB / 20,000 files on small sites, up to 1 GB / 100,000 files on sites with more than
  a million videos), so it can never fill your disk.
- the database results cache (`runtime/cache/`).

**Clear cache** is in the top bar of the admin and on Speed & protection. Use it when:

- a change you made does not show on your site,
- you uploaded new theme files,
- you upgraded JavCMS.

Your open admin pages stay open. Clear cache also runs a short clean-up (see below).

You do not need Clear cache after you hide, show or delete a video or a category: the site changes at once.
An import does not empty the cache either: new videos appear in the lists within 5 minutes.

If you use Cloudflare: JavCMS tells Cloudflare which pages it may keep for 5 minutes (only pages for visitors who
are not signed in). Use the Cache Rule shown on Speed & protection (see [SECURITY.md](SECURITY.md)); do not add a
"cache everything" rule, or visitors may see another visitor's theme or language. Without a purge token, a deleted
page can still show at Cloudflare for up to 5 minutes.

## Automatic clean-up and counts (housekeeping)

Without clean-up, the cache folders and some tables would grow forever, and big sites would count their videos
on every page. JavCMS does this work in the background, a little at a time.

What it does:

| What | Rule |
|---|---|
| Category and home page counts | Counted in the background (at most every 5 minutes after a change, every 15 minutes during a big import, and once a night). Visitors never wait for a count |
| View counters | Collected and written once every 5 minutes |
| Sitemap | Updated per block of 5,000 videos when videos change. The installer, "Finish upgrade" and `tools/upgrade.php` build the files right away ("Finish upgrade" on a site upgraded from 1.1.0 can come before the blocks: then housekeeping builds the files after them). A small rows list per block (`runtime/scale/sitemap_rows/`) answers files that were never built without reading the video table |
| Search engine IP lists | Downloaded once a day (Google, Bing, DuckDuckGo, Apple); the installer and "Finish upgrade" download them at once. Until then the lists shipped with JavCMS are used |
| Search index | New and changed videos are added within minutes |
| Page cache | Old pages removed; kept under its size limit |
| Cache files | When they are expired, or older than 3 days (sooner when the folder gets crowded) |
| Compiled theme files | When the theme files they came from no longer exist |
| Log files | Older than 30 days. The log folder is kept under 100 MB |
| Automatic settings snapshots | The newest 20 per folder and all from the last 30 days are kept |
| Verification messages | Older than 30 days |
| Referral visits | Older than 90 days |
| Saved search results | When expired, and never more than 20,000 rows |
| AVDB import details | Only the 3 newest imports keep their full details; the history keeps the newest 30 imports |

What it **never** touches: videos, articles, categories, comments, members, viewing history, points,
orders, your uploads and your database backups.

### Cron: when you need it

On a small site, this work runs by itself while people visit your site. On a site with more than a million videos,
page visits are not enough: Speed & protection shows "Set up cron". Add this line in the "Cron Jobs" page of your
hosting panel (every 5 minutes):

```
*/5 * * * * php /home/YOURUSER/public_html/tools/housekeeping.php >/dev/null 2>&1
```

Replace the path with the real path of your site folder, and use the same PHP version as your site
([HOSTING.md](HOSTING.md) shows the PHP program for cPanel, DirectAdmin and Plesk). Speed & protection prints both
cron lines of your site (this one and the import line) ready to copy.

### See what the clean-up did

If you have a terminal (SSH) on your hosting, run this in your site folder:

```
php tools/housekeeping.php --status
```

It shows the last run, the last full pass over the cache, how many files and bytes were removed in total,
and any errors. An empty `"errors": []` means all is well.

Other options of the same tool:

- `php tools/housekeeping.php` runs one short pass.
- `php tools/housekeeping.php --all` cleans the whole cache folder in one go (useful once after an upgrade).
- `php tools/housekeeping.php --dry-run` only counts; nothing is deleted.
- `php tools/housekeeping.php --sitemap-files-only` only builds the sitemap files: the index, then the video files that
  are missing or out of date, newest first. It stops after 30 seconds; `--sitemap-budget=600` gives it 10 minutes.
  Run it again until it shows `"left":0`. Add `--scheme=https` (or `--scheme=http`) when your preferred canonical
  protocol is "auto" and nobody has opened your sitemap yet: the command line cannot know which protocol your visitors use.
- `--sitemap-budget=N` also sets the time of the sitemap step of a normal run (default 30 seconds);
  `--no-sitemap-files` skips that step.
- `php tools/housekeeping.php --write-crawler-lists` is for people who build a JavCMS package: it downloads the search
  engines' published IP lists into `application/data/crawlers/` (the lists the package ships) and prints
  `{"write_crawler_lists": ...}`; exit code 0 means all four lists were written. Run it right before you package a
  release. A site never needs it: housekeeping keeps its own copies current.

Until a big video file of the sitemap (1,000 videos or more) is built, search engines get a lighter version of it: the
same addresses and dates, without the video details. For the files of the default language it comes from the block's
rows list (`runtime/scale/sitemap_rows/`, about 140 KB per 10,000 videos, about 100 MB with 7 million videos), so it
costs no reads of the video table. Housekeeping builds the full file within minutes with cron (or PHP-FPM); without either, one
file at a time while people visit your site. Above 5 million videos use cron or PHP-FPM: building every file once takes
about 4-9 minutes of work with 7 million videos ([HOSTING.md](HOSTING.md)). **Clear cache** never touches the rows lists, and you never need to delete
them; none is written while less than 1 GB of disk is free.

## Search

Search uses its own **search index** (a separate table, `vod_ft` with your prefix). The first search for a word is
fast in every language, also with millions of videos.

How search works:

- It finds **whole words**. The last word also finds words that start with it, when it has 3 or more letters
  ("ama" finds "amateur").
- Results are newest first. For very common words the page says "about N results".
- Every result page is reachable. Single words have numbered pages to the last one, and so have tags of one plain
  word (Latin letters and digits) that the term map proves exact: no other tag or category word contains it (for
  example "leggings"). The term map keeps the videos of every word that is in 500 videos or more and of every tag
  word (tables `vod_ft_map` and `vod_ft_mapt`; new videos are added within minutes, about 2 seconds per 10,000 new
  videos with 7 million videos). Longer searches show numbered pages first, then **Older results** and **Oldest**.
- Actor names, the other tags (plural or compound words, for example "blowjob" when "blowjobs" is also a tag, and tags
  that are also category names, for example "milf"), several names at once and names with special characters give the
  same exact results through the slower text match. Page 1 shows "N+ videos" with links to the next page and to
  **Older**. On the next pages a very common name shows "10,000+" with numbered pages for its first 10,000 videos (to
  page 501, which links **Older**) once its count is known; a rarer name, or one whose count does not finish in time,
  keeps "N+ videos" with links to the next page and to **Older**. **Older** continues to the oldest video; a page
  number above 501 is never linked (typed by hand it can answer "not found"). On a big site a page far down can get
  the simpler page on a cold database. A tag that
  the term map cannot prove at that moment (changes still waiting, new videos not yet in the map, word statistics from
  an earlier 1.2.0 build) takes the same way until it can. Saving or re-importing old videos does not turn a tag's
  numbered pages off: up to 1,000 such videos per tag are placed by their date; with more, the tag uses the text match
  until the search index is rebuilt (Speed & protection asks for a rebuild after many such saves).
- Page 1 of a tag, actor or category filter lists its newest videos also when they are rare among the newest videos of
  the site: the page then reads the name's matches once (see [HOSTING.md](HOSTING.md), "Known slower cases"). A name
  without a word index (only words under 3 letters, a code or name with "_", "%" or an apostrophe in it, or a word that
  more than 50,000 videos hold) reads the plain text match for up to about 2.5 s instead: on a cold big site its page
  1 can then show fewer videos, with **Older**.
- When the count of a filter page (tag, actor, category, director) cannot finish in 0.6 seconds, the page shows its
  videos with an open pager (the pages up to the next one, at most page 501, and **Older**) instead of waiting for the
  count.
- A page after the last result answers "not found" at once (search and category pages from a kept "not found" page,
  without a database query).
- Movie codes such as `ABC-123` are found as one word.

### Build the search index

A new install starts with a ready search index. After an upgrade, Speed & protection shows
**"Build the search index: until then, searches use the slower path."**:

- Click **Build search index**. It works in short steps of 9 seconds with a progress bar. Keep the page open.
- On a site with more than a million videos the button shows the command instead. Run it over SSH:

  ```
  php tools/search-index.php --backfill
  ```

Until it is ready, search keeps working the old way.

After a big import, Speed & protection may say **"Search order is approximate after your big import"**. Click
**Rebuild search index** (or run `php tools/search-index.php --rebuild`) at a quiet time. Search keeps working while it runs.

**Word statistics.** Search keeps statistics of how common each word, tag and actor name is, and the term map is built
from them. They are refreshed when they are due (more than 5 % new videos since the last refresh, older than a week, or
made by an earlier 1.2.0 build) by every rebuild, at the end of each command-line import, by `php tools/upgrade.php`
(with the whole term map) and by **Finish upgrade** (with the term map when it fits into one minute; else housekeeping
rebuilds the term map with cron or on PHP-FPM / LiteSpeed hosting). Housekeeping and the cron line above never refresh
the statistics themselves. Housekeeping needs up to about 256 MB of PHP memory to rebuild the term map (64 MB + 60 MB
per million videos; 220-232 MB measured with 7.1 million) and raises PHP's limit for it by itself, so the cron line
works with PHP's default limit of 128 MB. Where the host keeps the limit lower, housekeeping skips the term map and
Speed & protection shows the memory it needs: then run `php tools/search-index.php --map` once (it raises its own
limit) or allow PHP more memory. After you upgraded a
site whose search index came from an earlier 1.2.0 build without `tools/upgrade.php` or **Finish upgrade**, run once:

```
php tools/search-index.php --terms
php tools/search-index.php --map
```

With 7 million videos the first takes under a minute, the second about 3.5-4 minutes. Until then, the tag pages use the
slower text match (see "How search works"), and the "same performer" lists of video pages may show other videos of
a performer with 100 or more videos than the newest ones.

### Search index commands

All commands work in your site folder over SSH and print one line of results:

| Command | What it does |
|---|---|
| `php tools/search-index.php --status` | state, size, waiting changes, running job |
| `php tools/search-index.php --backfill` | fills an empty search index (first time after an upgrade) |
| `php tools/search-index.php --rebuild` | builds a fresh copy and swaps it in; add `--restart` to drop a stopped earlier run |
| `php tools/search-index.php --terms` | refreshes the word statistics (run `--map` after it) |
| `php tools/search-index.php --verify` | compares the index with your videos; `--fix` queues the differences |
| `php tools/search-index.php --auto` | the routine search-index work in one go: adds waiting changes, then runs a rebuild that is due, a due refresh of the word statistics or a missing term map. Nothing runs it for you: the cron line above runs housekeeping only (see "Word statistics") |
| `php tools/search-index.php --map` | builds the term map of the deep single-word and tag pages (housekeeping builds it with cron or on PHP-FPM / LiteSpeed hosting when it is missing or older than the word statistics; `--map-append` adds the newest videos, which housekeeping also does) |
| `php tools/search-index.php --optimize-ft` | optional clean-up of the word index. It changes a server-wide MySQL setting while it runs: only on your own server, with a database user that may do that |

**Do not add a FULLTEXT index to the video table yourself.** On a big site it rebuilds the whole table and
search would not use it.

### Filter word list

The actor, tag, category and director filters find their exact results through a word list of those fields (table
`vod_ft_vocab`). `php tools/upgrade.php` builds it after an upgrade; housekeeping and the importers keep it current. You
normally never touch it. The commands, over SSH in your site folder:

| Command | What it does |
|---|---|
| `php tools/vod-ft-vocab.php` | state of the word list (built, current, words per field) |
| `php tools/vod-ft-vocab.php --build` | builds it when it is missing, out of date or older than a day (a stopped build continues) |
| `php tools/vod-ft-vocab.php --rebuild` | starts a new full build now; the old list stays in use until it is done |
| `php tools/vod-ft-vocab.php --off` | switches the list off: every filter uses the plain text match (slower on a big site) until `--on` |
| `php tools/vod-ft-vocab.php --on` | switches it on again |

## Lists, counts and deep pages

To keep every page fast with millions of videos:

- Category pages show the exact number of videos (counted in the background, at most 5 minutes old).
- Lists with filters (year, area, letter, language) show "10,000+" above 10,000 results and continue with **Older**
  after their last numbered page.
- There are no page limits: every list is reachable to its last page, also sorted by views or likes (page maps for
  the lists by date and rank files for the sorted lists, built by housekeeping). Only a page after the last one answers
  "not found". Actor pages and the tag pages of the slower text match (see "Search" above) number at most their first
  10,000 videos and reach every older one through **Older**.
- Search engines are asked not to index list pages after page 50 on big sites (SEO › **Hide list pages after page**);
  the pages are still served.

The size level, the page-map state and the other values are shown under Speed & protection › Details.

## Database size

Check the size of your tables in **Admins & backup › Backup & restore** (every table with its size),
and the total against your limit in **Speed & protection**. [HOSTING.md](HOSTING.md) tells you what to expect.

The biggest tables are usually:

| Table (without prefix) | What is in it | How to keep it smaller |
|---|---|---|
| `vod` | Your videos | Import fewer videos, or delete videos you do not need; the slim-index tool (below) |
| `avdb_translation` | Video titles and descriptions in other languages | Import fewer languages |
| `vod_ft` | The search index | It follows your videos and languages |
| `vod_ft_map`, `vod_ft_mapt` | The term map of the search index (deep single-word search pages, the tag pages it proves exact and the "same performer" lists of video pages): about 32-38 MB per million videos | Kept by itself |
| `avdb_import_map` | One small record per imported video (to recognise it at the next import) | Kept by itself |
| `avdb_import_item` | Details of your imports (for undo) | Kept small by itself: only the 3 newest imports keep details |
| `vod_search` | Saved search results | Kept small by itself |

### Slim-index tool (above a million videos)

The video table has 37 indexes. The slim-index tool removes the 24 that big sites do not need. The table gets about
a quarter smaller and big imports get faster. It never runs by itself. Run it **before** a big import:

```
php tools/db-tune.php --slim-indexes --dry-run
php tools/db-tune.php --slim-indexes
```

The first line only shows what it would do and the free space it needs. Make a backup first.

### Duplicate titles

**All videos › Duplicates** shows the videos that have the same title. Above a million videos the page shows a command
instead, because one pass over every title takes minutes there:

```
php tools/vod-duplicates.php
```

It writes a summary to `runtime/vod_duplicates.json` and the list to `runtime/vod_duplicates.csv` (title, copies, lowest
id, highest id). `php tools/vod-duplicates.php --recycle --keep=min` only counts the copies it would move to the recycle
bin (every copy except the one with the lowest id; `--keep=max` keeps the highest id); add `--yes` to move them. You can
bring them back from the recycle bin in the admin.

### Deleted videos and the database files

**Deleting videos frees space inside the database for new videos, but the files do not shrink.**
If your host counts the file size, run this after you deleted many videos (for example "Remove all videos from
<source>"):

```
php tools/db-tune.php --reclaim --dry-run
php tools/db-tune.php --reclaim
```

The first line shows the free disk space needed. The second rebuilds one table at a time; do it at a quiet time.
Without a terminal, ask your host, or use **Optimize table** in phpMyAdmin on the big table.

### MySQL 8 binary logs

MySQL 8 keeps a copy of every change for 30 days ("binary logs"). A big import can fill gigabytes of disk with them:
a whole xvidapi import writes about 52 GB of them with MySQL 8's row format (MariaDB with binary logs turned on: about
41 GB in its default mixed format, about 89 GB in row format with row annotations). On a single server switch them off
with `skip-log-bin` before the first big import, or keep 1 day with `binlog_expire_logs_seconds=86400` and leave room
for the logs of the running import; see [HOSTING.md](HOSTING.md).

## Backups

The clean-up never makes backups for you. **Make your own backups regularly**, for example once a week
and always before an upgrade.

**Database backup in the admin**

1. Open **Admins & backup › Backup & restore**.
2. Click **Back up everything** (or tick some tables and click **Back up selected tables**).
   Keep the page open until it is done.
3. Click **Download** and keep the file on your computer or in cloud storage.
4. Delete old backups from the list after you downloaded them. Backups stay on your server
   (in `application/data/backup/`) until you delete them, and they use your disk space.

**Restore:** on the same page, open the **Restore database** tab, find the backup and click **Restore**.
Type `RESTORE` to confirm. The tables in it go back to that moment. Changes made after the backup are lost.

**Large databases:** a big site can be too large for a backup in the browser. Use the backup tool
of your hosting panel or phpMyAdmin **Export** instead. Many hosts also make automatic daily backups; ask yours.

**Files backup**

Your database backup does not contain your files. Also save these from time to time:

- `upload/` (logos, icons, uploaded pictures and ad files)
- `application/extra/` (your settings)
- `application/data/` (install lock, legal page texts, custom titles)
- `application/database.php` and `application/route.php`
- `.htaccess` or `web.config` if you changed them

**Run SQL** in the admin refuses some commands on purpose (they answer "Invalid format").
See [SECURITY.md](SECURITY.md), "Run SQL is limited on purpose". Use phpMyAdmin for those.

## Logs

JavCMS does not keep a log file of its own on a normal site: file logging is **off** in the shipped
`application/config.php` (`'log' => ['type' => 'test', ...]`, which throws log lines away).
When something goes wrong, look at the **PHP error log** in your hosting panel. When you ask for help,
that log tells what went wrong. If a log file does appear in `runtime/log/`, the automatic clean-up removes
files older than 30 days and keeps the folder under 100 MB.

**Do not turn on file logging on a live site.** If you change `'type' => 'test'` to `'type' => 'file'`, the
framework writes every PHP notice and warning of older, inherited code to `runtime/log/`: tens of thousands
of lines per hour on a busy site. That fills your disk, slows the site and hides the real errors.
Turn it on only for a short test on a copy of your site, then set it back to `'test'`.

## A short monthly checklist

1. Make a backup and download it.
2. Look at **Overview** in the admin. It shows problems that need attention.
3. Run **Admins & backup › Security scan**.
4. Check the database size in Backup & restore.
5. Look for a new JavCMS release on GitHub or Telegram. Read [../UPGRADE.md](../UPGRADE.md) before upgrading.
