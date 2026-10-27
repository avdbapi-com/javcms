# Upgrade from the previous release

This guide moves your site to the newest JavCMS release. Your videos, members, settings and uploads stay. Nothing in
your existing data is rewritten.

JavCMS comes only from its GitHub releases, https://github.com/avdbapi-com/javcms/releases. The updater downloads from
there by itself; when you update by hand, download the zip from there too ([INSTALL.md](INSTALL.md), "Download
JavCMS").

Which version does your site run? The admin shows it on **Overview › System details** ("Program version"); from 1.2.1
on, **Admins & backup › Updates** shows it too.

| Your site runs | How to update | Time |
|---|---|---|
| 1.2.1 or newer | One click in the admin: "From 1.2.1 on: one click" below | a few minutes |
| 1.2.0 | Once with the update bridge, a single file: "From 1.2.0: the update bridge" below. After that, one click | a few minutes |
| 1.1.0 | By hand with the zip of the newest release: "From 1.1.0: by hand" below | about 30 minutes |

A site on 1.2.0 or newer can always be updated by hand as well: "Update by hand" below.

Whichever way you take to 1.2.1 or newer: afterwards the button of the visitor theme switcher (when the switcher is on) is
visible on every page from the first screen (setting "Always visible floating button"). To keep the 1.2.0 behaviour,
choose **Only at the end of the page** under **Where the button shows** on **Site & theme › Themes**
([docs/THEMES.md](docs/THEMES.md)).

## From 1.2.1 on: one click

Only the founder admin account can update: the first account of the site, the one made during the install. Other
admins see a short notice on the Updates page, even when the permission is ticked for them.

1. Open **Admins & backup › Updates**. When a newer release exists, the page shows its release notes and
   **Review and update**. The admin checks GitHub by itself at most every 12 hours; when a newer release is known, you
   also see it as a badge on **Admins & backup** and on **Updates**, in the to-do bell of the top bar and on the
   **Overview**. **Check now** asks GitHub at once.
2. Click **Review and update**. The updater checks your site: PHP, database, free disk space, write permissions and
   the files you changed. It tells you how many files change and about how long the update and the maintenance page
   take. Items marked "Blocks the update" must be fixed first; then click **Review and update** again.
3. If you changed release files, the Review lists them. Choose **Replace them with the new release (recommended)**:
   your copies go into the backup and come back with a rollback. **Keep my versions** leaves them as they are, which
   is not recommended: the new release may not work with old files.
4. Type your password, tick the confirmation and click **Update now**. Keep the page open until it says the update is
   done: a few minutes on a normal site; visitors see the maintenance page for about one or two of them. Big sites and
   slow hosts take longer; the Review names the estimate for your site.

What the update does, in this order: it downloads the release from GitHub (the releases of avdbapi-com/javcms),
checks its signature, backs up every file it replaces plus your settings and your database, shows visitors a
maintenance page, replaces the files, runs the new version's upgrade steps, checks that the home, list, detail and
admin pages work, and turns the maintenance page off. If anything fails, it rolls back by itself.

- **Cancel** stops the update without any change for as long as the maintenance page is not on yet (an unfinished
  database backup is removed).
- A closed tab, a reload or a dropped connection is not a problem: open the Updates page again. A second tab follows
  the running update by itself. While files are being replaced, every admin page shows the update's progress page;
  when nobody drives the update any more, it asks for the founder password and **Continue** takes it to the end. If
  nobody continues for 3 minutes while visitors see the maintenance page, the visitors' own requests roll the update
  back. An update that was started but never driven before the files changed (the page was closed early) ends by
  itself after 2 hours, without any change.
- **Roll back to** the previous version (the button names it, for example **Roll back to 1.2.1**) stays on the same
  page for 7 days. It puts back the files and the version number of the previous version; your settings stay as they
  are now. When the update changed database tables in a way the old version cannot read, you choose whether those
  tables come back from the update's backup (changes made since then in those tables are lost) or stay. If that backup
  was deleted in Backup & restore, only the files can be rolled back, and the page says so. Should the restore of the
  tables fail during the rollback (the files are back all the same), the result names the database set to restore
  them from by hand and the date until which it is kept: 7 days from that failure, also when the update itself is
  older.
- Several releases behind? You always get the newest release your version can take. When the newest release needs a
  newer starting version, the page says so and offers the newest release that accepts your site first; after it, the
  next click brings you to the newest one.

**What an update keeps and what it changes:**

- `application/database.php`, `install.lock`, `upload/`, add-ons (`addons/`, `static/addons/`), your database
  backups, themes or theme files that are not part of the release, and your own files stay as they are.
- Your settings in `application/extra/` keep their values, and the search engine lists in `application/data/crawlers/`
  stay as they are; a file of these two folders that your site does not have yet is added. The update writes the new
  version number into `application/extra/version.php`, adds a setting that the new version brings when your settings
  do not have it yet (it never changes a value you saved; 1.2.1 adds **Where the button shows** of the visitor theme
  switcher, set to "Always visible floating button") and rebuilds the ad files (see "Your ad settings after an
  update" below).
- A file of your installed release that the new release no longer has is removed only when you never changed it (the
  backup keeps a copy); files you changed, theme files and the entry scripts are never removed.
- A changed `.htaccess` or `web.config` is always kept (so are a changed `robots.txt`, `favicon.ico`, `manifest.json`
  and `crossdomain.xml`); the release's new copy waits in `runtime/update/merge/<version>/` for you to compare and
  merge (`.htaccess` and `web.config` there as `htaccess.jcupd.txt` and `web.config.jcupd.txt`).
- Release files never go into `runtime/`: the update keeps its own work files in `runtime/update/` and clears the
  caches there.

**Files the updater needs** are replaced even when you choose **Keep my versions**: the entry scripts `index.php`,
`api.php`, the admin entry (also when you renamed `javcms.php`: the updater finds the renamed file and updates it in
place) and `install.php`, `tools/update.php`, the two tools that make an update wait for their jobs
(`tools/housekeeping.php` and `tools/import.php`), the two files whose code the upgrade steps of the new release run
(`application/common/util/ScaleSchema.php` and `tools/upgrade.php`), the Updates page, its menu rows and scripts, and
the update engine. The Review lists them ("Replaced even with "Keep my versions", because the updater needs them") and
the backup keeps your copies.

**What visitors see:** for the minute or two in which the files are swapped, a "being updated" page in the visitor's
language (English, Vietnamese, Simplified or Traditional Chinese from the browser's language, else the site's). It
answers HTTP 503 with Retry-After, so search engines and API clients come back later and nothing is indexed; it reloads
itself and has a "Try again now" link. The progress page that the admin pages and `install.php` show during the swap
tells anybody who is not the founder only the phase and the progress, never versions, file names, backups or errors.

**When something fails:** any failure after the first file was replaced (disk full, a file that cannot be written, a
PHP time limit, a failing upgrade step, a failing health check) rolls back automatically: the replaced files come back
from the backup, new files go away, settings the upgrade steps changed come back (settings you saved during the update
are kept), and database tables the new version changed in a way the old one cannot read come back from the backup too.
Failures before that (no connection, a wrong download, a bad signature, a backup that does not read back correctly,
too little disk) stop the update without touching anything. The page and the log say what happened, in your admin
language. When the pages still fail right after a rollback (for example on a host whose PHP cache keeps the new code a
few seconds longer), the result says so instead of "works as before". When a firewall or CDN in front of the site
refuses the server's own check of its pages (401, 403, 429 or 503), the browser that runs the update checks the pages
instead, so a good update is not rolled back because of the firewall. A rollback that cannot finish waits for you: see
"If an update cannot finish" below.

**Logs and backups:**

- Every run has a log: **Download the log** on the Updates page (English; the page shows the same lines in your
  language). No passwords or keys are ever logged.
- The copies of the replaced files are in `runtime/update/runs/<run>/backup/` (`.htaccess` and `web.config` are kept
  there as `htaccess.jcupd.txt` and `web.config.jcupd.txt`, so the web server never reads them).
- The database backup is first written into `application/data/backup/javcms-update/<date-time>/` (with a
  `README.txt`). A database up to 128 MB is backed up whole; after every part was read back and checked, the set moves
  into the folder of **Admins & backup › Backup & restore** and is listed there under the update's start time. Above
  128 MB the update keeps the structure of every table plus the data of the tables the update changes and of small
  tables; that partial set stays in its `javcms-update/<date-time>/` folder and is not listed in Backup & restore
  (restoring it there would not give you your site back), because the update's rollback uses it.
- Both kinds are removed after 7 days; a rollback that is running or waits for you keeps them until it ends, and a
  rollback whose restore of the database tables failed keeps the update's backup (files and set) for 7 days from that
  failure (its message names the date). Closing a waiting rollback with **Close this run** while files are still
  listed as not back ends that exception: copy the files you still need from
  `runtime/update/runs/<update>/backup/files/` first. An update that stops before any file changed removes its
  unfinished database backup, and the message says so.

**Without an outgoing connection** (a server that cannot reach GitHub): download `javcms-<version>.zip`,
`javcms-<version>.json` and `javcms-<version>.json.sig` from the release page on GitHub, upload the three files into
`runtime/update/inbox/` and click **Check now**: the Updates page (and the bridge of a 1.2.0 site) installs from there
and checks the signature the same way. On a 1.2.1 or newer site, upload the manifest of the version your site runs
too (`javcms-<installed version>.json` and its `.sig`, from that version's release page): the Review then compares
your files with it and can list the files you changed. When **Check now** could not reach GitHub, the Review and
**Update now** use only the stored copies and the inbox for the next 30 minutes.

**Security:** every release is signed (Ed25519) and lists the SHA-256 of every file; the public keys are part of the
code (`application/common/util/updater/Keys.php`). A release is offered only when its signature is valid, it names the
product "javcms", it is a stable release and it is newer than the installed version; everything else is refused, and a
package in the inbox is checked the same way. Downloads use HTTPS with certificate checks and go only to GitHub's own
hosts (`api.github.com`, `github.com` and its release download hosts), and a download is cut at the size the signed
list names. The update starts only after the founder password, and five wrong passwords pause password entry for 15
minutes.

**Your ad settings after an update:** an update rebuilds the files the ad engine generates from its settings (the same
as **Clear cache**), so `application/extra/jcx.php`, `application/data/jcx/live.php` and `static/jcx/c*.json` get new
build times; your ads and their key stay as they are.

## From 1.2.0: the update bridge (one file, once)

JavCMS 1.2.0 has no updater yet. It gets the one-click updater with a single file, the update bridge
`javcms-update.php`, once. Afterwards the site updates from **Admins & backup › Updates**.

1. Download `javcms-update.php` and `javcms-update.php.sha256` from the newest release on GitHub,
   https://github.com/avdbapi-com/javcms/releases/latest (both are in the **Assets** list). Optional: check it like the
   zip before you rename it ([INSTALL.md](INSTALL.md), "Download JavCMS"), for example
   `sha256sum -c javcms-update.php.sha256` on Linux (it prints `javcms-update.php: OK`).
2. Upload it into your site's root folder **as `install.php`**: replace the existing `install.php` (a running site
   does not use its installer). **Never remove `install.lock`** for this.
3. Open `https://your-domain/install.php` in the browser in which you are signed in to the admin with the founder
   account.
4. Click **Check for updates**, then **Check this site**. The page names how many files change and about how long the
   update and the maintenance page take: the first update of a 1.2.0 site replaces almost every file, so count a few
   minutes, one to three of them with the maintenance page.
5. Changed files: keep **Replace them with the new release** (the default; your copies go into the backup). The bridge
   carries the file list of 1.2.0, so it tells the files you changed apart from the release without downloading
   anything else. **A site built from the source code** (not installed from the release zip) or a site where many
   release files were edited sees thousands of "changed" files: choose Replace, never **Keep my changed files**. The
   files the updater needs are replaced in both cases.
6. Type the founder password, tick the confirmation and click **Update now**. Keep the page open.

When the update is done, the bridge has replaced itself with the new release's `install.php`, and the admin has the
Updates page (opened now, `install.php` says "The update to JavCMS 1.2.2 is finished." (the newest version) with a link to the admin;
later, and for everybody else, "This site is installed"). Reloading the page during the update is fine: it shows the
update's progress and, when it lost its key, asks for your password to continue. If the update fails, 1.2.0 stays
exactly as it was; the bridge page says why and offers **Restore the original install.php**.

- **The page still says "Installation is locked" after the upload?** Your host keeps PHP files in its cache (OPcache
  without timestamp checks): restart PHP (or clear OPcache) in your hosting panel and open `install.php` again.
- **Cannot sign in from that browser?** Click **Show my one-time code** on the bridge page: it names a file
  `runtime/bridge-<code>.txt` for this browser. Create that empty file with FTP or the file manager of your hosting
  and click **Continue** (the founder password is still needed).
- **Signed in, and the bridge still asks you to sign in?** Some hosts keep PHP's session files in a folder that PHP
  code may not look into (Plesk's default `open_basedir` is the web space and the temp folder, while the sessions are in
  `/var/lib/php/sessions`), or keep the sessions in Redis or Memcached: the bridge cannot see your admin sign-in there.
  Use **Show my one-time code**, or add the session folder to `open_basedir` in your panel (for example
  `{WEBSPACEROOT}{/}{:}{TMP}{/}{:}/var/lib/php/sessions`).
- **Not updating now?** Do not leave the bridge on the site: click **Restore the original install.php** (it also
  removes the update's temporary files), or delete `install.php` and upload the `install.php` of your own 1.2.0
  package. A bridge file starts an update only for 180 days after it was built; an older one says so and points to the
  newest `javcms-update.php` on GitHub (`php install.php --check` says the same and ends with exit code 3). A bridge
  whose build date lies more than two days ahead of the server's clock does not start either: its page names both
  dates. Check the server's clock (date and time zone) first.
- **Rolled back to 1.2.0 later?** **Roll back to 1.2.0** stays on the Updates page for 7 days. JavCMS 1.2.0 has no
  Updates page: when the rollback ends, the page keeps only the rollback's progress and result (**Download the log**
  still works) and offers **Admin home**, and `install.php` is the update bridge again. To update again, open
  `https://your-domain/install.php`.

**With SSH**, after uploading the bridge, run in the site folder (as the user that owns the site's files):

```
php install.php --check
php install.php --apply
```

`php install.php --help` lists the options (`--yes`, `--keep-modified`, `--url=`). The command line first asks the
web server, through the uploaded file, how its PHP cache works. On a host whose OPcache never re-reads changed files
(`opcache.validate_timestamps=0`) it does not start at all (exit code 3, "update in the browser instead"; nothing is
changed): the command line cannot reach that cache, and the 1.2.0 entry scripts have no maintenance page yet. Use the
browser there, signed in as the founder: in the browser the update runs inside the web server and resets its cache.
While the web server still runs its cached copy of the old `install.php`, the command line does not start either
(exit code 3: restart PHP, then use the browser). Should the web server keep the cached 1.2.0 entry scripts during a
command-line update anyway, the command line rolls the update back and says so (exit code 5): restart PHP, then update
in the browser.

## Update from the command line (SSH, from 1.2.1 on)

The command line uses the same engine as the Updates page:

```
php tools/update.php --check
php tools/update.php --apply [--yes] [--keep-modified] [--url=https://your-domain/]
php tools/update.php --rollback [--yes] [--db-restore]
php tools/update.php --status
php tools/update.php --resume
php tools/update.php --close-attention [--yes]
php tools/update.php --cancel
php tools/update.php --help
```

- `--check` looks for a newer release. `--apply` prints the estimate (files, minutes, minutes of maintenance) and asks
  before it starts (`--yes` does not ask). `--keep-modified` keeps the release files your site changed (not
  recommended). `--rollback` goes back to the version before the last update, within 7 days; `--db-restore` also
  brings back the database tables the update changed in a way the old version cannot read (refused when that backup
  was deleted in Backup & restore).
- `--status` shows the running run and the last runs, `--resume` continues a run that was interrupted (a closed
  terminal, a crash) and tries a rollback that needs attention again, `--close-attention` closes such a rollback after
  you finished it by hand. `--cancel` stops a run that has not changed any site file yet; when a step is running it
  waits up to a minute for it, or the run stops at the end of that step (when no page or command drives the update any
  more, run `--cancel` again a moment later).
- Run it as the user that owns the site's files (it warns otherwise): files it writes must stay writable for the
  Updates page.
- After the files are replaced, the update checks the site's pages through the site's own address. So the command line
  refuses to start when this server cannot reach the site, or when the site refuses the server's requests (an HTTP
  password, an IP allow-list, a CDN or firewall challenge). Then pass `--url=` with an address that reaches this web
  server directly, or update from the Updates page in a browser. `--check` says which applies.
- The command line cannot reset the web server's PHP cache (OPcache) itself: it asks the site to do it. A web server
  whose OPcache never re-reads changed files and may not be reset by the site (`opcache.validate_timestamps=0` together
  with `opcache.restrict_api` or disabled opcache functions) cannot take an update from the command line: it stops
  before anything changes (exit code 3). Ask your host to allow `opcache_reset` for the site (the Updates page blocks
  such a host with the same reason), or update by hand and restart PHP afterwards.
- The long builds of a big catalogue are left to housekeeping, as on the Updates page; run `php tools/upgrade.php`
  afterwards to do them at once.
- Hosts that disable `set_time_limit` or `ignore_user_abort` (common on shared hosting, also for the command line) are
  fine: the updater, `tools/update.php` and `tools/upgrade.php` call them only where PHP has them, and
  **Keep my versions** replaces a site's own older copies of the files that call them. One exception: the search-index
  build of `tools/upgrade.php` (its step 4, only while the index is not built yet) runs in a child PHP that needs
  `passthru` and, on PHP 8, `set_time_limit`; on a host that disables either, step 4 says "not built here" and leaves
  the index to the admin (or run `php tools/upgrade.php --no-search-backfill`).
- An update waits up to two minutes for a running import or housekeeping job before it replaces files, and
  `tools/import.php` and `tools/housekeeping.php` skip a run while an update replaces files, so your cron lines can
  stay as they are.

| Exit code | Meaning |
|---|---|
| 0 | Done, or nothing to do |
| 1 | Failed, or rolled back |
| 2 | Wrong use: an unknown option, a folder that is not an installed JavCMS 1.2.1 or newer site, or PHP older than 7.4 |
| 3 | Blocked before any change: a requirement, the server cannot check the site, `runtime/update/` cannot be used, or the web server's OPcache never re-reads files and may not be reset |
| 4 | Another update, rollback or check is running at this moment, or a rollback waits for you (needs attention: `--apply` and `--rollback` say so and stop): try again when it has ended (`--status` shows it). Two `--apply` started at the same time (a cron line and an admin) run the update once; the other one ends with 4 |
| 5 | The web server kept running its cached copy of the PHP code: restart PHP (PHP-FPM, Apache, or "clear OPcache" in your hosting panel). The update was rolled back, or a rollback (also one finished by hand and closed with `--close-attention`) is on disk but not yet in the web server's cache |

`--resume` ends with the same codes (3 when it stops before any change).

## If an update cannot finish

Do this only when the Updates page says **needs attention**, or when the site keeps showing "being updated" and the
Updates page cannot be reached.

A rollback that cannot finish (a backup copy missing or unreadable, a file the server may no longer write) is tried 12
times with growing pauses (a few minutes in all, also when visitors' requests drive it after the page was closed) and
then waits for you. Visitors keep the maintenance page meanwhile, so they never get half a site. The
page says **needs attention**, keeps the update's own reason, names the file with the hand procedure and the backup
folder, and offers **Try the rollback again** and **Close this run**.

1. **Fix the cause** if you can (free disk space, file permissions, a backup copy you moved) and click **Try the
   rollback again** (the founder password is asked; a few more tries follow, and the page follows them). With SSH:
   `php tools/update.php --status` names the run, the reason and the hand-procedure file;
   `php tools/update.php --resume` tries again.
2. **By hand**, when that is not possible: `runtime/update/runs/<run>/attention.txt` lists every step in your admin
   language, file by file, from where the rollback stopped: the files to copy back from the backup folder it names,
   `runtime/update/runs/<run>/backup/files/` (when a manual rollback stopped: the backup folder of the update it rolls
   back, another run than the one of `attention.txt`; same site paths; `.htaccess` and `web.config` are there as
   `htaccess.jcupd.txt` and `web.config.jcupd.txt`), the files that have no copy (take them from your own backup or
   from the zip of the version you go back to), the files the update added (delete them), the folders it created
   (delete them when they are empty) and the settings the upgrade steps changed (copies in `backup/settings-pre/`).
   The whole plan of the update's files is in `runtime/update/runs/<run>/plan.php`.
3. **If the update changed the database** (the page names the tables): restore them from the update's database set,
   in **Admins & backup › Backup & restore** (a whole-database set, listed under the update's start time), or from the
   files in `application/data/backup/javcms-update/<date-time>/` (a partial set of a big database: import them with
   phpMyAdmin or the `mysql` command in the order of its `README.txt`).
4. **Delete `runtime/update/maintenance.php`**: visitors get the site again. Then open the Updates page: when every
   file of the list is back (and no database table had to be restored), it closes the run by itself and you can
   update again; a run whose steps name database tables never closes by itself. Otherwise it still says
   **needs attention** (without the maintenance chip) and offers **Close this run**: tick "I finished the rollback by
   hand", type the founder password and click **Close this run** (with the box ticked, Enter in the password field
   does the same, and **Try the rollback again** is off until you untick the box). The run ends as closed by you, the
   version number is the old one again, and the files that are still not as before the update are named. Clicking
   **Try the rollback again** once (box not ticked) also ends it at once when the files are back.
   **With SSH**, do not delete `runtime/update/maintenance.php` yourself: run `php tools/update.php --close-attention`
   while it is still there. The command deletes the file itself and, through it, first clears the web server's PHP
   cache (OPcache) of the files you put back, a cache the command line cannot reach otherwise; the Updates page, the
   run page and the bridge clear that cache too when they close a run. Run after the file was deleted, the command
   line says what the run learnt of the web server's cache: nothing to do (no OPcache, or one that re-reads changed
   files), "restart PHP" with exit code 5 when the run knows that the cache never re-reads files, else what to check.
   When the pages still behave like the newer version after the hand procedure, restart PHP (or clear OPcache in your
   hosting panel); the last line of `attention.txt` says the same.
5. **A run that goes back to 1.2.0** (an update through the bridge, or **Roll back to 1.2.0**): the entry scripts of
   1.2.0 have no maintenance page, so `attention.txt` lists them as the last files to copy back, after every other
   step; visitors keep the maintenance page until then and get the whole 1.2.0 site from that step on. After the hand
   procedure the site is 1.2.0 again. It has no Updates page, and the procedure itself deletes `tools/update.php`, so
   nothing closes the run by itself: open `https://your-domain/install.php` (the update bridge again) in the browser in
   which you are signed in as the founder; it shows the run with **Close this run** (tick "I finished the rollback by
   hand", type the password).

On a host where the page cannot read your admin sign-in (sessions in Redis or Memcached, or a session folder outside
`open_basedir`), the run page offers the password only in the first hour after the rollback stopped. After that, finish
by hand as `attention.txt` lists it, delete `runtime/update/maintenance.php` and close the run on the Updates page, or
run `php tools/update.php --resume` (try again) or `php tools/update.php --close-attention` over SSH.

Keep the downloaded log of the run (**Download the log**) for support.

## Hosting notes for updates

- **Write permission.** The updater replaces a file by writing a new copy next to it and renaming it over the old one,
  so PHP needs write permission on every folder the update writes into; the replaced file keeps its permission bits.
  The Review checks every such folder and names each file that PHP may not write (the read-only flag or the
  permissions) before anything changes. On most shared hosting PHP runs as your own hosting user and this is already
  the case.
- **OPcache with `validate_timestamps=0`** (common on tuned hosts) is supported where the site may reset OPcache: the
  updater clears the cached copies of every file it writes. Where it may not (`opcache.restrict_api` pointing
  elsewhere, the opcache functions in `disable_functions`), the Updates page blocks the update with that reason (ask
  your host to allow `opcache_reset` for the site), and the command line stops before any change (exit code 3).
- **LiteSpeed / OpenLiteSpeed:** the shipped `.htaccess` lines mark the update's requests "noabort", so a step
  finishes even when the tab closes. Keep them when you edit the rules. In a sub-folder install the condition of the
  maintenance window must name the folder: `RewriteCond %{DOCUMENT_ROOT}/<folder>/runtime/update/maintenance.php -f`
  (the comment next to the rule and `deployment/README.md` show it).
- **Slow hosts, `open_basedir` and short PHP time limits:** every part of the update works in short steps that
  continue where the last one stopped; a step that keeps failing at the same place stops the update with a clear
  reason. **Cancel** answers in time even on a host with a 10-second limit.
- **`runtime/` and `runtime/update/`** must be real folders of the site on a local disk (not links to another folder,
  and not on a file system without file locks, such as some network mounts). Otherwise the Updates page and
  **Check now** say so and nothing starts.
- **Outgoing connections:** the updater contacts only `api.github.com`, `github.com` and GitHub's release download hosts
  (`release-assets.githubusercontent.com`, `objects.githubusercontent.com`), with a User-Agent that names the installed
  version and nothing about your site. A host without these connections uses the inbox (see "Without an outgoing
  connection" above).
- **`set_time_limit` / `ignore_user_abort` in `disable_functions`** (PHP 8 removes such a function, and calling it is
  an error): supported. The update engine, `tools/update.php`, `tools/upgrade.php` and the list-index step of an update
  call them only when PHP has them, and "Keep my versions" replaces a site's own older `ScaleSchema.php` /
  `tools/upgrade.php`. The search-index build of `tools/upgrade.php` (step 4, only while the index is not built yet)
  needs `passthru` and, on PHP 8, `set_time_limit` in its child PHP: where either is disabled it says "not built here"
  (build it in the admin, or run `php tools/upgrade.php --no-search-backfill`).
- **PHP sessions the update cannot read** (the session folder outside `open_basedir`, the default on Plesk, or
  sessions in Redis or Memcached): the bridge and the run page cannot see your admin sign-in. Updates work all the
  same (the bridge's one-time code, the Updates page, the command line); adding the session folder to `open_basedir`
  lets the pages recognise you again.
- **No ext-sodium?** Signatures are then checked with the bundled pure-PHP code (slower, same result).
- **Windows / IIS** is supported: files in use are retried, and `web.config` is treated like `.htaccess`.

## Update by hand

A manual update is always possible, for example on a host where the updater cannot run. From 1.2.0 or 1.2.1 to 1.2.2:

1. Back up your database and your files (step 1 of "From 1.1.0: by hand" below).
2. Download `javcms-1.2.2.zip` from GitHub ([INSTALL.md](INSTALL.md), "Download JavCMS") and extract it on your
   computer.
3. Delete from the extracted files: `application/database.php`, `application/route.php` and every file in
   `application/extra/` **except `version.php`** (the new `version.php` holds the version number 1.2.2 that the admin
   and the updater read; without it your site would still call itself 1.2.0 or 1.2.1). Keep everything else: the new
   `application/data/`, `upload/` and `runtime/` folders contain only new example files and empty placeholders, and
   the release has no `install.lock` and no `addons/`, so yours stay in place.
4. Upload the rest into your site folder and replace the existing files. If you upload in parts, upload `static/`
   before `application/` (or everything in one go), so visitors get the new theme switcher at once. If you renamed
   `javcms.php` or changed `.htaccess` / `web.config`, do as in step 6 of "From 1.1.0: by hand".
5. Click **Clear cache** in the admin, reload with Ctrl+F5, and purge your CDN and OPcache if you use them (step 7 of
   "From 1.1.0: by hand" below).
6. With a terminal, run `php tools/upgrade.php` once: it adds the new setting of the visitor theme switcher (step
   "1b. Settings"); the rest of it was already done by your 1.2.0 upgrade, and it does nothing twice. Without a
   terminal nothing else is needed: a site that has not run it uses the same default.

### Theme files changed in 1.2.2

No theme file changes in 1.2.2 apart from the notice comment at the top of every JavCMS file: its Telegram line names the new group and the support account ([CHANGELOG.md](CHANGELOG.md)). It does not change your pages, so an edited theme keeps working; compare your copy with the lists below only when you come from 1.2.0.

### Theme files changed in 1.2.1

If you edited a theme, compare your copy with these files of the new release before you upload your copy again (the
one-click update lists the release files you changed by itself). Files that are not listed are unchanged since 1.2.0,
apart from the short notice comment at the top of every JavCMS file, which names the GitHub repository from 1.2.1 on
(see [CHANGELOG.md](CHANGELOG.md); it does not change your pages). The two `preview-*.webp` pictures are the theme's
preview pictures on the Themes page.

<!-- BEGIN theme-files-121 -->
<!-- theme files: 1.2.1 vs 1.2.0 -->
- **Changed in every theme:** `preview-dark.webp`, `preview-light.webp`
- `template/crimson-modern/`: changed `html/public/include.html`
- `template/ember-modern/`: changed `assets/css/thermal.css`, `html/public/brand.html`
- `template/honey-modern/`: changed `html/public/brandvars.html`
- `template/neon-modern/`: changed `html/public/brand.html`
- `template/onyx-modern/`: changed `html/public/brand.html`
- `template/topaz-modern/`: changed `html/public/brand.html`
- `template/velvet-modern/`: changed `html/public/brand.html`
<!-- 42 files in 17 themes (JAVCMS Classic not in this release) -->
<!-- END theme-files-121 -->

## From 1.1.0: by hand

These steps move a 1.1.0 site (2026-10-15) to the newest release in one go: everything that 1.2.0 changed (below) and
the changes of 1.2.1 ([CHANGELOG.md](CHANGELOG.md)). The updater cannot do this jump: it updates sites from 1.2.0 on.
Your videos, members, settings and uploads stay. Nothing in your existing data is rewritten.

Plan about 30 minutes, plus the time of two background steps on big sites (step 8). Do it at a quiet time of day.

### What changes in short

- **1.2.1 adds on top:** one-click updates for every later release (**Admins & backup › Updates**, see "From 1.2.1
  on: one click" above); the button of the visitor theme switcher, when the switcher is on, visible on every page by
  default, with all its settings on **Site & theme › Themes** (`php tools/upgrade.php` adds the new setting, step
  "1b. Settings"); new preview pictures of the themes and the default logo without its tagline. Details:
  [CHANGELOG.md](CHANGELOG.md), section 1.2.1.
- **New:** xvidapi as a second video source, the **Import videos** page with size estimates, the
  **Speed & protection** page, a search index, a page cache and fair limits per visitor network,
  the command-line importer `tools/import.php`, and the tools `tools/upgrade.php`, `tools/search-index.php`
  and `tools/db-tune.php`.
- **Protection is on after the upgrade, with high values:** turning visitors away when busy, per-visitor request
  limits (300 searches or filters and 2,400 other pages per minute per visitor network) and the query time limit.
  Checked search engines (Googlebot, Bingbot, YandexBot, DuckDuckBot, Applebot) are never blocked. Under attack mode
  is off. Nothing to set; to change a value see [docs/SECURITY.md](docs/SECURITY.md), section 10.
- **Themes:** every theme changes (category chips, the International block on the home page, quality badges,
  small-picture handling). If you edited theme files, read "Theme files changed in 1.2.1" (under "Update by hand"
  above) and "Theme files changed in 1.2.0" below **before** you copy.
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

### Step 1. Back up everything

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

### Step 2. Write down what you changed

Check these before you copy new files:

- **Did you rename `javcms.php`?** Write down your admin file name.
- **Did you edit `.htaccess` or `web.config`?** Keep a copy of your changes.
- **Did you edit files inside a theme folder?** The upgrade replaces theme files. Copy your changed files somewhere safe
  and compare them with the lists "Theme files changed in 1.2.1" (under "Update by hand" above) and "Theme files
  changed in 1.2.0" below.

### Step 3. Optional: switch the site off while you work

Site & theme › Site identity › **Site on or off** › switch the site off › Save.
Visitors see a short "closed" message. You switch it on again at the end.

### Step 4. Pause your imports

If automatic imports are on, open **Import › Import videos** and click **Pause automatic imports**,
and pause a running import. You resume them in step 9.

### Step 5. Prepare the new files on your computer

1. Download the new release from GitHub, the same way as for a new site ([INSTALL.md](INSTALL.md), "Download
   JavCMS"): open https://github.com/avdbapi-com/javcms/releases/latest, click `javcms-<version>.zip` in the
   **Assets** list and, if you like, check it with its `.sha256` file. Download it only from there.
   Then extract the new zip on your computer.
2. **Delete these from the extracted files**, so they do not replace yours:
   - `application/database.php` (your database settings)
   - `application/route.php` (your page address settings)
   - every file in the folder `application/extra/` (your site settings) **except `version.php`**. Keep the new
     `application/extra/version.php`: it holds the version number (1.2.1) and the license name that the admin shows,
     and the admin uses the version number to load its new styles. It holds no setting of yours.
3. Keep everything else. The new `application/data/`, `upload/` and `runtime/` folders contain
   only new example files and empty placeholders. They do not replace your data.
   The release has no `install.lock`, so your own lock file stays in place.
4. If you deleted `install.php` from your site for safety, you can delete it from the new files too
   (see [docs/SECURITY.md](docs/SECURITY.md)).

### Step 6. Upload and replace

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

### Step 7. Clear the cache and open the admin

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

### Step 8. Finish the upgrade (two steps you start)

Two steps can take minutes on a big site, so JavCMS never starts them from a visitor's page view.
Open **Admins & backup › Speed & protection**:

1. **Counts and list indexes** says "The upgrade is not finished: N list indexes are missing".
   Click **Finish upgrade (about N min)**. It adds the missing indexes to the video table: the list and sort
   indexes, and the word indexes (FULLTEXT) `ft_vod_name`, `ft_vod_actor`, `ft_vod_tag`, `ft_vod_class` and
   `ft_vod_director` that make the actor, tag, category and director filters fast (a fresh install of 1.2.0 or newer
   has them from the start). Indexes that are already there are skipped. Seconds on a VPS with 500,000 videos, a few
   minutes on shared hosting, longer with millions of videos. Your site stays online; while a word index is added, saving
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

**Deep pages.** Since 1.2.0 there are no page limits: every list, tag, actor and search result is reachable, down to
the oldest video (actor pages and the tag pages that the term map cannot prove exact number at most their first 10,000 videos and
reach every older one through "Older"; see [docs/MAINTENANCE.md](docs/MAINTENANCE.md), "Search"). On a big site this
needs a page map, rank files and a term map, which maintenance builds by itself after the upgrade
(`php tools/upgrade.php` builds the first ones at once). Until they are ready, the line **Deep pages** on Speed &
protection says "Deep pages being prepared (N %)" and very deep pages may answer slower or ask visitors to retry; pages
near the start work as before.

### Step 9. Check your imports

- Old AVDB jobs and automatic imports work as before; they are now marked as source AVDB.
  The first refresh of an old video rewrites it once; after that, unchanged videos are skipped quickly.
- Resume what you paused in step 4.
- The first time you open **Import › Import videos**, JavCMS asks for your database limit
  ([docs/HOSTING.md](docs/HOSTING.md)).
- To add xvidapi videos, read [docs/IMPORT.md](docs/IMPORT.md) and [docs/HOSTING.md](docs/HOSTING.md) first.
  **Whole catalog** is the default choice; it imports everything that fits your database (from this page or by
  command line) and stops cleanly at the storage target (80 % of your limit).

### Step 10. Check your site

Open these and check that they look right:

- your home page, a category page, a video page and a watch page (the video plays),
- a search,
- your site on a phone,
- `https://your-domain/sitemap.xml` and `https://your-domain/robots.txt`,
- the admin: Overview, All videos, Import videos, Speed & protection (section **Protection against floods**: every
  protection "On", the search engines listed, Under attack mode "Off").

If you switched the site off in step 3, switch it on again now.

### Theme files changed in 1.2.0

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

### If something goes wrong: back to 1.1.0

1.2.0 adds tables and columns (1.2.1 adds none); 1.1.0 ignores them. You need the backup of your 1.1.0 files from
step 1: JavCMS 1.1.0 and older are not offered for download (they were never published on GitHub). To go back:

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
   theme pages that the new release prepared, and 1.1.0 cannot show them: **until they are gone, every page of your
   site shows an error page ("500").** In your hosting panel: File Manager › open `runtime/temp` › select all › Delete.
   Without a file manager: sign in to the admin (it keeps working) and click **Clear cache** in the top bar at once;
   it empties the same folder.
4. Click **Clear cache** in the admin (also when you emptied the folder by hand), then open your home page and a video
   page to check that they show again.

If the data itself is damaged, restore the database: Admins & backup › Backup & restore › **Restore database** tab ›
**Restore** next to your backup (type `RESTORE` to confirm), or import your exported file in phpMyAdmin.

Ask for help in the Telegram group: https://t.me/+jj6McO-iYfA0MTM1 or write to Support: https://t.me/avdbapi_support . Tell us what you see and which step you were on.
