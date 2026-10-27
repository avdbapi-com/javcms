# Install JavCMS

This guide is for a normal shared hosting account with a control panel
(cPanel, aaPanel, DirectAdmin, Plesk, CyberPanel and similar).
It takes about 15 minutes.

You need:

- a domain that points to your hosting,
- PHP 7.4 to 8.5,
- MySQL 5.7.8+ or MariaDB 10.2.3+ (MySQL 5.6+ or MariaDB 10.1+ also works, but search in other languages will not).
  MariaDB 10.2.3+ gives the fastest search,
- the JavCMS zip file from GitHub (see "Download JavCMS" below).

## Download JavCMS

Get JavCMS only from its release page on GitHub. Copies on other websites can be old or changed, and JavCMS is free:
if somebody asks you to pay for it, it is not an official download. javcms.net is the live demo (adult content, 18+),
not a download place.

1. Open https://github.com/avdbapi-com/javcms/releases/latest. This address always opens the newest JavCMS release.
2. In the **Assets** list of the release, click `javcms-<version>.zip` (for release 1.2.2: `javcms-1.2.2.zip`).
   Your browser saves the file. Newer releases carry their newer version number in the file names.
3. Optional: click `javcms-<version>.zip.sha256` too. It holds one line: the SHA-256 checksum, then the file name.

The other files of a release are for updates and are not needed for a new site: `javcms-<version>.json` and
`javcms-release.json` with their `.sig` signature files, and the update bridge `javcms-update.php` with its `.sha256`.

Do not install from the **Source code (zip)** and **Source code (tar.gz)** links that GitHub adds to every release:
GitHub makes them from the repository, they are not the tested release file, and no checksum file comes with them.
Always use `javcms-<version>.zip`.

**Check the download (optional).** A checksum proves that the zip is complete and unchanged. Run one of these in the
folder with the zip. The result must be the checksum in the `.sha256` file:

| Computer | Command |
|---|---|
| Windows (Command Prompt) | `certutil -hashfile javcms-1.2.2.zip SHA256` |
| Mac (Terminal) | `shasum -a 256 javcms-1.2.2.zip` |
| Linux | `sha256sum -c javcms-1.2.2.zip.sha256` (it prints `javcms-1.2.2.zip: OK`) |

If the two values differ, download the zip again. Never install a zip whose checksum does not match.

**With a terminal on your server (SSH)** you can download, check and extract in one go. Replace
`/home/YOURUSER/public_html` with the real path of your site folder:

```
cd /home/YOURUSER/public_html
curl -fLO https://github.com/avdbapi-com/javcms/releases/download/v1.2.2/javcms-1.2.2.zip
curl -fLO https://github.com/avdbapi-com/javcms/releases/download/v1.2.2/javcms-1.2.2.zip.sha256
sha256sum -c javcms-1.2.2.zip.sha256 && unzip -q javcms-1.2.2.zip
```

`unzip` runs only when the check prints `javcms-1.2.2.zip: OK`. No `curl` on your server? `wget` works with the same
two addresses. Then go on with step 3 from point 3 and delete both downloaded files. For a newer release, write its
version number everywhere instead of `1.2.2` (the release page shows the number).

Earlier releases: JavCMS 1.1.0 and older are not on GitHub and are not offered for download.

## 1. Choose the PHP version

1. In your hosting panel, open the PHP settings for your domain
   (for example "MultiPHP Manager" or "Select PHP Version").
2. Pick PHP 8.1 or newer if you can. PHP 7.4 to 8.5 all work.
3. Make sure these extensions are on: **pdo_mysql, mbstring, curl, gd, xml, zip, fileinfo, openssl**.
   Most hosts turn them on by default.
4. Optional, but helpful: set `upload_max_filesize` and `post_max_size` to at least `20M`.
   You need this only if you want to upload your own video ads.

## 2. Create the database

1. In your hosting panel, open "MySQL Databases" (or "Databases").
2. Create a new, empty database. Write down its name.
3. Create a database user with a strong password. Write down the user name and password.
4. Give this user **all rights** on the new database.

The installer does not create the database for you. It only fills an empty one.
It never overwrites tables that already exist.

## 3. Upload the files

1. Open the File Manager of your hosting panel and go to your site folder
   (often `public_html` or `www`).
2. Upload the JavCMS zip file (`javcms-<version>.zip`) there.
3. Extract (unzip) it in that folder.
   You should now see `index.php`, `install.php`, `javcms.php`, and the folders
   `application`, `static`, `template` and others **directly** in your site folder.
   If they are inside an extra folder, move them up one level.
4. You can delete the zip file now.

Nginx, IIS or OpenLiteSpeed? Your server needs its own rules.
Read [deployment/README.md](deployment/README.md) before you continue.
On Apache (most shared hosting) the included `.htaccess` file does this for you.
If your File Manager hides files that start with a dot, turn on "Show hidden files" and check that `.htaccess` is there.

## 4. Set file permissions

PHP must be able to write to some folders.
The installer creates missing folders by itself and tells you in plain words if something is not writable.

These must be writable by PHP:

| Path | Why |
|---|---|
| `runtime/` | Cache and temporary files |
| `upload/` | Uploaded pictures, logos and ad files |
| `application/` | The installer saves your settings here |
| `application/extra/` | Your site settings |
| `application/data/` | Install lock, backups, legal page texts |
| `application/database.php` | Your database settings (written by the installer) |
| `application/route.php` | Page address settings |
| `static/jcx/` | Recommended: the ad settings file for visitors |

**Linux hosting:** folders `755` and files `644` are usually right when the files belong to your hosting user.
If the installer says "Not writable", set the folders above to `775`.
**Never use `777`.**

**Windows / IIS:** give the site's application pool user "Modify" rights on the paths above.

Optional: if your site folder itself is writable, JavCMS can also write `favicon.ico`, `ads.txt`
and a few small files that ad networks or search engines ask for into the site root.
If it is not writable, the admin offers a download button and you upload the file yourself.

**One-click updates** (Admins & backup › Updates) replace the files of the release, so they need PHP to be able to
write the site's folders as well. On most shared hosting PHP runs as your own hosting user and can do that with the
permissions above. If it cannot, the update's check names every file it may not write before anything changes, and
you can update by hand instead ([UPGRADE.md](UPGRADE.md)).

## 5. Run the installer

1. Open `https://your-domain/install.php` in your browser.
   (Opening your home page before the install also takes you there.)
2. Read the agreement and click **Agree and install**.
3. **System requirements check.** Every row should say it is OK.
   If a row fails, fix it (see steps 1 and 4) and reload the page. Then click **Next step**.
4. **Database configuration.** Enter:
   - **Server address:** usually `127.0.0.1` or `localhost` (your host tells you).
   - **Database port:** usually `3306`.
   - **Database name**, **Database username** and **Database password** from step 2.
   - **Database prefix:** keep `javcms_`. Use a different prefix only if the database already holds another JavCMS.
     The prefix uses small letters, numbers and `_`, starts with a letter and ends with `_`.
5. Click **Test database connection**. Wait for "Database connection successful".
6. **Your site.** Check the **Site name** and the **Site URL**.
   The site name is the main heading of your home page in every theme: pick a short name of your own.
   The URL is filled in from your browser. Use `https://` if your site has an SSL certificate.
   This makes HTTPS the address used in sitemaps and for search engines.
7. **Your videos.** Answer **Which catalogs will you import?**
   - **AVDB (Japanese)**: the Japanese categories are turned on.
   - **xvidapi (international)**: the Japanese categories are turned off; the xvidapi genres will appear in the menu.
   - **Both**: the Japanese categories plus one menu for the international genres.
   - **Decide later** (preselected): the Japanese categories are turned on, as in earlier versions.

   With xvidapi or Both, choose the **Genre menu** name: **International** (recommended) or **Western**.
   You can change all of this later in Videos › Categories. Before you import a big catalog, read
   [docs/HOSTING.md](docs/HOSTING.md).
8. **Admin account.** Choose an admin name (3 to 30 letters, numbers, `_` or `-`).
   Choose a password of **12 to 72 characters** and type it twice.
   Use a long, unique password. Save it in a password manager.
9. Click **Install now**. Wait for "Installation complete".
10. You are taken to the admin sign-in page.

The installer also creates the tables of the search index, the page counts and the importer, so a new site needs
no "Finish upgrade" step. At the end it stores the sitemap index (the video files of the sitemap follow as you import
videos) and downloads the search engines' current IP lists, so Googlebot and the other search engines are recognised
by today's lists from the first visit (if a download fails, the lists shipped with JavCMS are used until housekeeping
gets the current ones).

The installer creates the file `application/data/install/install.lock`.
**Keep this file.** It stops anybody from running the installer again.
If you open `install.php` later, it says "This site is installed": the installer is locked (JavCMS 1.2.0 and older
say "Installation is locked").

## 6. Sign in to the admin

The admin address is:

```
https://your-domain/javcms.php
```

Sign in with the admin name and password from step 5. The sign-in page asks for a short number code (captcha).

**Change this address soon.** Everybody who knows JavCMS knows `javcms.php`.
It takes two steps, and both are needed:

1. Rename `javcms.php` to a name only you know, for example `panel-k7q2x.php` (not `admin.php`).
2. Open `.htaccess` (Apache, OpenLiteSpeed) or `web.config` (IIS) and find the **two lines** that contain
   `index|api|javcms|install`. In both lines, replace `javcms` with your new name without `.php`.

If you skip step 2, the new address answers **403 Forbidden**. The full guide, with the exact lines and Nginx,
is in [docs/SECURITY.md](docs/SECURITY.md), "Change the admin address".

## 7. First steps after install

The first admin page (**Overview**) shows a checklist. Work through it from top to bottom:

1. **Name your site and add a logo.** Site & theme › Site identity.
   Also upload your own **site icon** there. See [docs/SEO.md](docs/SEO.md).
2. **Choose a theme.** Site & theme › Themes. Click **Preview** to look, then **Use this theme**.
3. **Import your first videos.** Import › Import from AVDB. Start small (100 videos).
   See [docs/IMPORT.md](docs/IMPORT.md).
4. **Turn on automatic import**, so new videos arrive by themselves.
5. **Write your search titles.** Search engines (SEO) › Search titles & descriptions.
6. **Decide who can sign up.** Members & comments › Sign-up & login.
7. **Add ads or analytics code.** Site & theme › Ads & tracking code. See [docs/ADS.md](docs/ADS.md).
8. **Make your first backup.** Admins & backup › Backup & restore › Back up everything.
9. **Protect your admin.** Change your password and keep the admin address private.

Also do these:

- Read and adjust your legal pages: Site & theme › Legal & age.
  Our texts are only examples, not legal advice.
- Public APIs and the receiving interface are **off** after install. Turn on only what you need.
- Send your sitemap to Google and Bing. See [docs/SEO.md](docs/SEO.md).
- Keep JavCMS up to date: **Admins & backup › Updates** looks for new releases on GitHub at most every 12 hours and
  installs them with one click, with a backup and an automatic rollback. Only the founder account (the admin account
  from step 5) can update. See [UPGRADE.md](UPGRADE.md).
- Visitors can pick a theme with the visitor theme switcher, a palette button on every page. Choose where it shows,
  or switch it off, on **Site & theme › Themes** (card **Visitor theme switcher**).
  See [docs/THEMES.md](docs/THEMES.md).

## 8. HTTPS (SSL)

Use HTTPS. Search engines prefer it, browsers warn about sites without it,
and **automatic AVDB imports only work with a public HTTPS address.**

1. Turn on a free SSL certificate in your hosting panel (often called "Let's Encrypt" or "AutoSSL").
2. Check that `https://your-domain/` opens without a warning.
3. In the admin, open Search engines (SEO) › Page addresses (URLs) and set
   **Preferred canonical protocol** to **HTTPS**. Save.
4. In Site & theme › Site identity, check that **Domain** shows your domain without `http://`.
5. Optional: let your hosting panel redirect `http://` to `https://` ("Force HTTPS").

The checklist on Search engines (SEO) › Search titles & descriptions tells you
when your site runs on HTTPS but your addresses still use HTTP.

## 9. Cron job

**A small site does not need a cron job.** JavCMS cleans up by itself while people visit your site,
and automatic imports are started by the AVDB service, not by cron.

You need cron when:

- you want big imports (for example the whole xvidapi catalog) to run faster and also when nobody visits your site
  (without cron, the admin page imports everything too, a little slower),
- your site has **more than a million videos** (Speed & protection then shows "Set up cron"),
- your site has very few visitors (to keep the cache folder small).

In your hosting panel, open "Cron Jobs" and add these lines (every 5 minutes):

```
*/5 * * * * php /home/YOURUSER/public_html/tools/import.php --run --max-minutes=4 >/dev/null 2>&1
*/5 * * * * php /home/YOURUSER/public_html/tools/housekeeping.php >/dev/null 2>&1
```

- Replace `/home/YOURUSER/public_html` with the real path of your site folder. Your File Manager shows it.
- Use the same PHP version as your site (7.4 or newer). Some hosts need the full path to PHP, for example
  `/usr/local/bin/ea-php74` or `/opt/alt/php74/usr/bin/php`. [docs/HOSTING.md](docs/HOSTING.md) shows the right one
  for cPanel, DirectAdmin and Plesk.
- **Import › Import videos** and **Speed & protection** print both lines for your site, ready to copy, and show
  "Cron last ran".

## Problems?

| What you see | What to do |
|---|---|
| A row in the system check says "Not writable" | Set the permissions from step 4, then reload. |
| "Could not connect to the database" | Check the server address, user name and password. Some hosts use `localhost`, others an address like `mysql.yourhost.com`. |
| Pages other than the home page show "404 Not Found" | Your server does not use the rewrite rules. On Apache, check that `.htaccess` was uploaded. On Nginx, add the rules from [deployment/README.md](deployment/README.md). |
| The admin page is blank or shows an error 500 | Check the PHP version (7.4 to 8.5) and the extensions from step 1. Your host's error log shows the exact reason. |
| `install.php` says "This site is installed" (or "Installation is locked") but you want to install again | Only if you really want to start over: delete `application/data/install/install.lock`, use an empty database, and run `install.php` again. Put the lock file back afterwards (the installer does this for you). |
| Search in other languages finds nothing or shows an error | Your database is older than MySQL 5.7.8 / MariaDB 10.2.3. Ask your host for a newer version. |

Still stuck? Ask in the Telegram group: https://t.me/+jj6McO-iYfA0MTM1 or write to Support: https://t.me/avdbapi_support
