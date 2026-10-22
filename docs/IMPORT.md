# Import videos from AVDB and xvidapi

JavCMS fills your site with videos from two sources. Both are built in: you do not need an API address
or a collection source.

| Source | What it has | Titles |
|---|---|---|
| **AVDB** (avdbapi.com) | about 524,000 Japanese videos (JAV), new ones every day | English plus 16 translated languages |
| **xvidapi** (xvidapi.com) | about 6.6 million international videos | original language only (mostly English); category names are translated |

Open **Import › Import videos** in the admin. The tabs on top choose the source. Each tab shows how many of its videos are
on your site. The page remembers the tab you used last.

Before a big import, read [HOSTING.md](HOSTING.md): it tells you how much database space and which hosting you need.

## The first time: your database limit

The first time you open the page, JavCMS asks **"How big may your database get?"**.
Enter the limit of your hosting plan in GB, or choose **I don't know** or **No limit (my own server)**.
JavCMS uses it to check every import before it starts, and pauses imports before the database gets full,
so your site keeps working. Change it later with **Change database limit**. Details: [HOSTING.md](HOSTING.md).

## xvidapi: choose how many videos

xvidapi has 6.6 million videos. **Whole catalog** is the default: JavCMS imports everything that fits your database.
Under **Choose what to import**:

| Choice | Videos | Database space (about) | Requests to xvidapi | Where it runs |
|---|---|---|---|---|
| **Whole catalog (recommended)** | 6,596,643 (all, no filters) | 12.1 GB (9.0 GB with the slim-index tool), plus room while it runs | 66 snapshot files, or 13,194 id windows (18.3 h) | this page or command line |
| Newest 10,000 videos | 10,000 | 19 MB | 10 (1 min) | this page or command line |
| Newest 50,000 videos | 50,000 | 94 MB | 50 (4 min) | this page or command line |
| Newest 100,000 videos | 100,000 | 188 MB | 100 (8 min) | this page or command line |
| Newest 1,000,000 videos | 1,000,000 | 1.8 GB (1.4 GB with the slim-index tool) | 2,000 (2.8 h) | this page or command line |
| Newest videos, my own number | your number | | | this page or command line |
| One category | the whole category | depends on the category | 1 per 1,000 videos | this page or command line |

**This page or command line: both import everything.** There is no size rule: the page starts any choice, and the
import keeps going by itself (see "Without cron" below). Cron is optional and faster.

**The storage target is the only stop.** When the whole catalog does not fit your database limit, you can still start
it: the import brings the newest videos first and stops cleanly when the database reaches **80 % of your limit**
(the storage target), so you get the largest newest part that fits. The estimate says where it stops: **"Stops at
about N videos when the database reaches X GB"**. Without a limit, the free disk decides the same way (JavCMS keeps
room for the closing search-index step plus 2 GB). The site-wide guard of [HOSTING.md](HOSTING.md) still pauses
every import at 90 % of the limit or when the disk is nearly full.

### Before you start: the estimate

**Before you start** shows, for your choice:

- **Videos** ("about N of 6,596,643 (storage target)" when the import stops at the storage target).
- **Where the videos come from**: "xvidapi snapshot: S files, X GB download" when xvidapi offers its export snapshot,
  else "id windows: N requests, about H h of provider pace".
- **Import time**: with this tab open, with the tab closed (while your site has visitors), and by cron. After the first
  10,000 videos, JavCMS uses your site's own speed ("your site imports about N videos per second").
- **Database space for this import** ("about 94 MB (up to 119 MB with complete rows)" for 50,000 videos).
- **Database after the import**, compared with your limit, with a bar.
- **Highest disk use while importing** (binary logs and the search index step included) and your free disk.
- **Cover copies**, when you tick **Copy cover images to my server** (about 12.7 KB per video; off by default).
- The **language line**: "Titles: original language only (mostly English). Visitors using other languages see these
  titles untranslated and find them with English words."
- A green line when everything fits ("This import fits your database limit and your free disk space"), or the
  **stops at about N videos** line. The start button stays in both cases.
- With MySQL 8 and more than 500,000 videos: a warning about binary logs (see [HOSTING.md](HOSTING.md)).
- Above 1,000,000 videos: the offer to run the slim-index tool first, with the dry-run command
  (see [MAINTENANCE.md](MAINTENANCE.md)).
- For more than 1,000,000 videos, or more than half of the space left, one checkbox with the numbers
  ("Yes, import about N videos (about X of the Y still free; about T)"). It only protects against a misclick.

### Filters

Under **Filters**, all off by default, so the whole catalog really is all 6.6 million videos:

- **Skip videos shorter than 60 seconds** (about a fifth of the catalog are short clips),
- **Skip the category "Unknown"** (without it, these videos go to the category **Other** under your International menu),
- **Skip videos in SD quality**, and **Title languages to import** with the share of each language in the catalog.

The categories "Xvidapi" and "Unknown" are never created.

### Categories: Grouped or Mirror

- **Grouped** (recommended): xvidapi's 64 categories go into about 12 clear categories (Amateur, Teen, Asian, ...).
- **Mirror**: one category on your site for every xvidapi category.

All of them go under one menu entry, **International** (or **Western**, if you chose that when you installed;
rename it any time in Videos › Categories). Untick a category and its videos are not imported.

On a site with only xvidapi videos, the genres appear directly in the menu and the empty Japanese categories stay
hidden. On a site with both sources, the menu shows the Japanese categories plus the International menu.

### Start from this page

Click **Start import**. The page says **"Keep this tab open: about N. If you close it, the import continues while your
site has visitors (about M) or by cron."** While the tab is open, the page runs the import in short steps.

### Without cron: the import continues by itself

Close the tab and the import goes on: after a visitor's page has been sent, JavCMS runs one short import step
(at most one every 30 seconds, only when the server is not busy, never inside a visitor's request). This needs a
server that can finish a request early (PHP-FPM or LiteSpeed, the usual hosting setup). On a server without it
(Apache mod_php), the page says **"This server cannot continue the import in the background; keep this tab open or
add the cron line."**; the import then moves while the tab is open or when cron runs.

### Faster: cron (optional)

With cron, imports run every 5 minutes at low priority in bigger steps, also when nobody visits your site.
The page shows the exact line with **Copy**:

```
*/5 * * * * php /home/YOURUSER/public_html/tools/import.php --run --max-minutes=4 >/dev/null 2>&1
```

1. Add the line in the "Cron Jobs" page of your hosting panel. [HOSTING.md](HOSTING.md) shows where, for cPanel,
   DirectAdmin and Plesk, and which PHP program to use when `php` is not found.
2. Wait 5 minutes. The page then shows **Cron last ran: <time>** in green.
3. From then on, large imports go to cron: the button says **Start (cron continues it)** and the job says
   **started by command line**.

You can also use the tool yourself over SSH:

```
php tools/import.php --list-providers
php tools/import.php --estimate --provider=xvid --scope=category --categories=11,43
php tools/import.php --start --provider=xvid --scope=full --confirm=1
php tools/import.php --start --provider=xvid --scope=recent --limit=100000 --preset=grouped
php tools/import.php --run --max-minutes=4
php tools/import.php --status
php tools/import.php --pause --job=12
```

`--confirm=1` is needed whenever `--estimate` asks for it: more than 1,000,000 videos (the whole xvidapi catalog
always is) or more than half of the space left. Without it `--start` stops and asks you to confirm.
`--run` works for up to 4 minutes and stops; run it again (or let cron run it) and it continues where it stopped.
A stopped or killed run loses at most one short step.

### The whole catalog

**Whole catalog** imports all 6,596,643 xvidapi videos when your database allows it
([HOSTING.md](HOSTING.md), last row), from this page or by command line.

- **With the xvidapi snapshot** (xvidapi publishes its catalog as about 66 compressed files plus feeds of changes and
  removed videos), JavCMS downloads the files one after the other, checks each one and imports it; a stopped download
  or import continues where it stopped. The provider part is a download instead of 13,194 requests (14 requests for
  1,000,000 videos in the 1.2.0 verification). Measured there with a test copy of the snapshot:
  1,000,000 new videos in 54 minutes (each 100,000 at 285-351 per second), and 500,000 more in 25 minutes with a
  512 MB buffer pool (each 100,000 at 307-359 per second), every video once.
  The whole catalog takes **about 6 to 6.4 hours**, plus about 2 hours for the search index at the end. The test
  machine was not limited to 2 cores and the test videos had shorter texts than real ones: allow more time on a small VPS.
- **Without the snapshot** (older xvidapi), JavCMS asks for the videos in id windows of 500: 13,194 requests at one
  every 5 seconds = 18.3 h, so the first import takes **about 18-24 hours**.

1. On a big catalog, run the slim-index tool first (the estimate offers it).
2. Keep **Whole catalog (recommended)** and check the estimate (videos, where it stops, times, disk).
3. Tick the checkbox with the numbers and click **Start import** (or **Start (cron continues it)**).

Your site stays online and fast while it runs. New videos become searchable at the end, when the search index is
updated ("N new videos are not searchable yet" until then). If the import reaches the storage target, the job ends
with **"Stopped at the storage target: N videos imported, X of Y GB used"**; raise the limit (or free space) and
start again: videos already imported are skipped.

### Where the new videos appear ("as new arrivals")

xvidapi has added no new videos since April 2026. If you import a big part of xvidapi into a site that already has
1,000 or more AVDB videos (or the other way round), JavCMS puts the new block **on top, as new arrivals**: the newest
xvidapi video first, in xvidapi's own order. The real upload date is kept as the release date of each video.
Without this, all xvidapi videos would sit behind every newer AVDB video and nobody would see them on the home page.
Imports into an empty site, the hourly sync and AVDB's daily new videos always use the real upload time.

## AVDB: find and review

The AVDB tab works in 3 steps: **1. Find videos**, **2. Review import**, **3. Progress and history**.
For large AVDB imports, **Large import** opens the same choices as for xvidapi (newest N, whole catalog).

### Before your first import: think about size

AVDB has more than 500,000 videos. Your hosting has limits.

- **Start small.** Import 100 to 1,000 videos first and check your site.
- **Languages make the database much bigger.** Every extra language adds a translated title and description
  to every video: about 22.6 KB per video with all 16 languages instead of 3.8 KB in English only.
  The full catalogue in all languages needs about 11 GB ([HOSTING.md](HOSTING.md)). If your hosting is small, choose
  **English only** or only the languages you need.
- **Cover images** stay on AVDB's servers by default. This saves disk space. Turn on
  **Download cover images to this site** only if you have enough space.

### Your first import

1. Open Import videos, tab AVDB. Wait until it says **Connected** and shows the first page of videos.
   Looking at videos does not change your site.
2. **Find videos.** Use **Quick start** (updated in the last 24 hours or 7 days), or search:
   a title, an exact movie code, a category, actress, director, studio, genre, tag, year or playback source.
   Click **Preview videos**. The preview shows 50 videos per page; you can choose 10, 20, 100 or the full
   page of 1,000. "All videos matching these filters" always works through the catalogue in pages of 1,000,
   whatever the preview shows.
3. Tick two or three videos if you want only those. **Details** shows the information before you import.
4. Click **Review import**.
5. Choose **what to import**:
   - **Only the videos I selected**, or
   - **All videos matching these filters** (stops after the item limit, 1,000 by default).
6. Choose **languages**: English only, or English plus available extra languages.
   All languages are selected by default. Untick what you do not need.
7. **Categories and players.** Each AVDB category goes into one of your categories.
   Fresh installs have the 7 AVDB categories: Censored, Uncensored, Uncensored Leaked, Amateur,
   Chinese AV, Hentai and English subtitle (turned off when you chose "xvidapi only" in the installer).
   **Automatically create missing AVDB categories** is on by default.
   If you hid one of your categories, the import uses it and keeps it hidden. Review shows it as, for example,
   "Uncensored → Uncensored (hidden)". Hidden categories are not shown to visitors
   (see [SEO.md](SEO.md), "Hidden categories"). Show them in Videos › Categories.
   The default player is chosen for you.
8. Choose **Save as drafts** if you want to check the videos before visitors see them,
   or **Publish on my site**.
9. Keep **Smart refresh and repair** for videos you already have. It updates changed information
   and skips videos that did not change.
10. Read the summary and click **Start this import**. Keep the page open while it runs.
11. Check the result in **3. Progress and history**, then look at the videos in Videos › All videos.

On a slow or cheap server, start with 10 to 50 videos per run to see how fast it goes.

## Automatic imports (hourly sync)

Automatic imports bring new videos by themselves, even when nobody visits your site.
They are **off** after install. One schedule serves both sources: AVDB and xvidapi run one after the other,
never at the same time.

1. Find videos with the filters you want and click **Preview videos**.
2. Click **Review import** and choose **All videos matching these filters**.
3. Check the languages, categories, player, images, update and publishing settings.
4. In **Automatic imports**, tick **Enable automatic imports**.
5. Choose **Repeat every**: 15 minutes, 1 hour, 6 hours, 12 hours or a day.
6. Click **Save reviewed settings**.

Good to know:

- **You do not need a cron job.** The AVDB service starts each run for you. If you have cron,
  `php tools/import.php --tick` every 5 minutes does the same on your own server.
- Your site must be reachable from the internet over **HTTPS**, at the site address you set in the admin.
  The AVDB service cannot start runs on `localhost` or on a site without HTTPS.
- Automatic imports only bring **new and changed** videos (and check for removed ones). Big imports
  ("newest 100,000", "whole catalog") are never started by the schedule: start them yourself on the import page;
  they continue by themselves (the hourly run also moves a running import one step on).
- The provider status line shows **Newest video at the provider: <date>**. xvidapi's newest video is from
  April 2026, so its hourly sync finds nothing new today. That is normal, not a broken import.
- **Pause automatic imports** keeps the current job. Turning automatic imports off stops future runs.
  Videos you already imported stay.
- If a run fails, it waits for you. Check **3. Progress and history**.
  The messages of automatic imports are shown in your admin language.

## Gentle on the providers

JavCMS never sends a visitor's request to AVDB or xvidapi. Imports ask xvidapi at most **one request every 5 seconds**,
shared by the page, the schedule and the command line on your site. The status line of each source shows the state:

- **Ready: one request every 5 s**, or **Pacing: next request in N s**.
- **Waiting after N failed requests: next try at <time>**: the provider did not answer; JavCMS waits longer each time
  (up to 1 hour) and continues by itself. After 8 failures in a row the import pauses with a message.
- **Paused until <time>: The provider asks for a browser check; its API must be exempt from the challenge.**
  The provider's own protection (Cloudflare) blocks the API for a while. JavCMS waits 1 hour; it does not retry
  in a loop. Nothing to do on your side.
- When the provider asks to wait (HTTP 429), the import waits as long as asked.

## Videos removed by the provider (takedowns)

When a provider removes a video, JavCMS hides it on your site too, carefully:

1. Once a week JavCMS checks which of your videos are still at the provider (large sites: a quarter of the videos
   each week, so every video is checked about once a month).
2. A video that is missing is checked again **at least 24 hours later**. Only when it is missing **twice** is it
   hidden (not deleted). Hiding can be undone.
3. **Safety stop:** if a check would hide more than 2 % of the videos of that source, or more than 5,000, or the
   provider suddenly answers with nothing, the check stops and hides nothing. The page then says **"The last check
   stopped and hid nothing: <reason>"**. A broken or hostile answer from the provider cannot empty your site.
4. Under **Videos removed by the provider** you see how many are hidden and how many were removed more than 30 days
   ago. **Delete the N older than 30 days…** deletes those for good, after a confirm. Nothing is deleted automatically.

## Videos you deleted stay deleted

When you delete an imported video, JavCMS remembers its provider ID. The next automatic import or big import does
**not** bring it back. To get it back on purpose, import it with **Only the videos I selected** and tick the option
to import deleted videos again. **Undo** and **Remove all videos from <source>** do not remember deleted videos,
so a smaller import later can bring them back.

## Pause, retry and undo

- **Pause** saves the progress. Choose the job under **Recent imports** and click **Resume** to go on.
- **Stop import** ends the job. Videos already imported stay.
- If you close the page, the import continues while your site has visitors, or by cron (see "Without cron" above).
  Jobs started by command line go on by cron.
- **Retry failed items** tries the failed videos again with the same settings.
- **Undo this import** asks first: "Remove the N videos created by this import? This takes about T". It removes
  those videos and their import records, in short steps. Videos you edited after the import are left alone.

**Undo works for your 3 newest imports.** After 3 newer imports, the details of an old import are removed
to keep your database small, and it can no longer be undone. The history list keeps your 30 newest imports.

### Remove all videos from one source

Under **Videos from <source> on your site**, **Remove all videos from <source>…** deletes every video imported from
that source and turns off its automatic import. Categories without videos are hidden. The page shows the number and
the time first; type the confirmation (for example `REMOVE 3000 VIDEOS`) to start. It runs in short steps and you can
leave the page.

**Deleting videos frees space inside the database for new videos, but the files do not shrink:** run
`php tools/db-tune.php --reclaim` or ask your host (see [MAINTENANCE.md](MAINTENANCE.md)).

## Videos you already have

- Videos imported before are recognised by their source and provider ID, even when the title changed.
- Videos that did not change at the provider are skipped quickly, without writing anything.
- **Smart refresh and repair** (default) updates changed text, details and playback links,
  adds new translations and keeps your own address, publishing status, counters and local covers.
- If an update would change the title or the video link of more than a fifth of a batch, the import pauses and asks you.
- **Skip it** keeps the existing video as it is.
- **Update only the fields I choose** lets you pick titles, details, covers, playback and translations.
- Locked videos and videos in the recycle bin are skipped.

## Delete videos

- **Move to recycle bin** keeps the video so you can restore it.
- **Permanently delete** removes the selected videos, their translations, comments and import records.
- **Clean all videos** (in the video list) removes every video. You must type `DELETE ALL VIDEOS` to confirm.
  Categories, players, settings and members stay.

These cannot be undone. Make a backup first: Admins & backup › Backup & restore.

## Troubleshooting

| Problem | What to do |
|---|---|
| No videos found | Remove a filter, check the movie code, or use fewer filters together. |
| The estimate says **"Stops at about N videos"** | Your database limit (or free disk) holds only part of this choice. You can start anyway: the newest videos come first and the import stops cleanly at the storage target. To get more, raise the limit if your plan allows it, or use the slim-index tool. |
| "Stopped at the storage target" | The import ended at 80 % of your database limit. Raise the limit (or free space) and start the same choice again: videos already imported are skipped. |
| The import stops when you close the tab | Your server cannot continue in the background (the page says so). Keep the tab open, or add the cron line (above). |
| **Cron last ran: never** although you added the line | Check the folder in the line and the PHP program ([HOSTING.md](HOSTING.md)). Run the line once over SSH to see the error. |
| "Database almost full": imports paused | Free space (delete videos, fewer languages), then raise the limit or wait. See [HOSTING.md](HOSTING.md). |
| "The provider asks for a browser check" | The provider's own protection blocks its API for a while. JavCMS waits and continues by itself. |
| "Provider unavailable" or a timeout | Your server must be able to open HTTPS addresses on the internet. Click reconnect. Progress is saved. |
| Certificate error | Your server's certificate list is old. Ask your host to update it. Do not turn off certificate checks. |
| A video does not play | Check that its player accepts the provider's link. The video host may also be down for a while. |
| Automatic imports say "need attention" | Check that your site address uses HTTPS and opens from the internet. Then open Progress and history. |
| xvidapi's hourly sync imports nothing | Normal: xvidapi has added no videos since April 2026 ("Newest video at the provider"). |
| New videos are not found by search | After a big import, the search index is updated at the end. See "N new videos are not searchable yet" in Speed & protection. |
| Imported videos land in a hidden category | You hid that category. Show it again in Videos › Categories, or choose another category for it in step 2 (Review import). |
| Another admin cannot see the import page | Give their role the "Import videos" rights in Admins & backup › Admin accounts. |
| The database gets too big | Import fewer languages, or fewer videos. See [HOSTING.md](HOSTING.md) and [MAINTENANCE.md](MAINTENANCE.md). |

AVDB API fields and limits: https://avdbapi.com/index.php/label/help.html
