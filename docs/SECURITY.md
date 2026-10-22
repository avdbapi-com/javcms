# Keep your site safe

Most hacked sites are hacked in simple ways: a known admin address, a weak password,
an open installer or wrong file permissions. This guide closes those doors.
It takes about 15 minutes.

## 1. Change the admin address

Everybody who knows JavCMS knows the address `https://your-domain/javcms.php`.
Give it a name only you know.

The notice on the admin **Overview** and the help in **My account & password** explain the rename itself.
Step 3 below (the two lines in `.htaccess` or `web.config`) is needed as well: without it the new address does not open.

1. Pick a new name with letters, numbers, `-` or `_`, for example `panel-k7q2x.php`.
   Do not use `admin.php`: JavCMS refuses to run under that name.
2. In your File Manager, rename `javcms.php` to your new name.
3. Tell your web server about the new name. **Do not skip this step:** the server only runs the four
   PHP files it knows (`index.php`, `api.php`, `javcms.php`, `install.php`), so a renamed file answers
   **403 Forbidden** until you change these lines.
   - **Apache** (most shared hosting): open `.htaccess` in your site folder. Find the two lines that contain
     `index|api|javcms|install`. In **both** lines, replace `javcms` with your new name without `.php`.
     Before:

     ```
     RewriteRule ^(index|api|javcms|install)\.php/(.*)$ $1.php?s=/$2 [B,QSA,END]
     RewriteRule ^(?:index|api|javcms|install)\.php$ - [END]
     ```

     After (for `panel-k7q2x.php`):

     ```
     RewriteRule ^(index|api|panel-k7q2x|install)\.php/(.*)$ $1.php?s=/$2 [B,QSA,END]
     RewriteRule ^(?:index|api|panel-k7q2x|install)\.php$ - [END]
     ```

     Change nothing else in these lines. Save.
   - **IIS:** open `web.config`. Two `<match url="...">` lines contain `index|api|javcms|install`
     (rules "JAVCMS entry PATH_INFO" and "JAVCMS four PHP entrances"). Replace `javcms` with your new name
     in both. Save.
   - **OpenLiteSpeed:** in the `.htaccess` you copied from `deployment/openlitespeed/root.htaccess`,
     change the two lines that contain `index|api|javcms|install` in the same way.
   - **Nginx:** in your `location ~` line that lists `index\.php|api\.php|javcms\.php|install\.php`,
     replace only `javcms\.php` (for example `panel-k7q2x\.php`). Leave the names like `$javcms_script`
     as they are. Reload Nginx. See [../deployment/README.md](../deployment/README.md).
4. Open `https://your-domain/panel-k7q2x.php` and sign in. If you see **403 Forbidden**, step 3 is not done
   (check both lines, and that you wrote the name without `.php`).
5. Check that `https://your-domain/javcms.php` no longer opens the admin.
6. Save your new address in your password manager. Do not post it anywhere.

After every upgrade, repeat steps 2 and 3: the new release contains `javcms.php` and `.htaccess` again.

## 2. Use strong passwords

- Admin passwords must be 12 to 72 characters. Longer is better. Use a password manager.
- Never use the same password as your email, your hosting panel or your database.
- Change your password: open the account menu in the admin top bar › **My account & password**.
- The admin sign-in page asks for a captcha code. Leave it on.
- Use **Lock screen** (account menu) when you leave your computer. After too many wrong passwords on the lock screen,
  the admin signs you out.
- Use a strong, separate password for your database user and your hosting panel too.

## 3. Give every person their own admin account

- Open **Admins & backup › Admin accounts**. Create one account per person. Do not share accounts.
- Give each account only the rights it needs. The role editor has presets and groups.
- The right **May paste HTML / JavaScript code** lets an admin add code that runs for every visitor.
  Give it only to people you fully trust.
- Rights with a red **Full access** mark are each enough on their own to take over the whole site
  (for example Run SQL, restoring a backup, or code on every page). Give them only to people you trust completely.
- An admin can give only the rights it has itself. Rights it does not have are greyed out in the role editor.
- Only the main admin (the account made during the install) can change the main admin account and accounts that
  have more rights. For everybody else these rows show **Locked**.
- Remove accounts of people who no longer work on your site.

### Run SQL is limited on purpose

**Advanced › Developer › Run SQL** (shown after **Show advanced tools**) answers **Invalid format** for commands that could read or
write files on your server or change the whole database server: for example `INTO OUTFILE`, `LOAD_FILE`,
`LOAD DATA`, `PREPARE`, `EXECUTE IMMEDIATE`, `SET GLOBAL`, `INSTALL PLUGIN` and anything that names the `sys` schema.
These words are refused anywhere in the command, even inside quoted text. Normal `SELECT`, `INSERT`, `UPDATE`,
`CREATE TABLE` and `DROP TABLE` commands still work (changes ask for an extra confirm).
If you really need such a command, use phpMyAdmin in your hosting panel.

## 4. Keep the installer locked

After the install, the file `application/data/install/install.lock` exists.
It stops anybody from running the installer again and taking over your site.

- **Never delete `install.lock`** on a live site.
- Check: open `https://your-domain/install.php`. It must say "Installation is locked".
- Extra safety: you can delete `install.php` after the install. The site does not need it.
  (An upgrade brings it back; delete it again.)

## 5. Set file permissions correctly

- **Never use `777`** on files or folders.
- Linux: folders `755`, files `644`, owned by your hosting user, is the normal setup.
- Only these need write access for PHP: `runtime/`, `upload/`, `application/extra/`, `application/data/`,
  `static/jcx/`, and the files `application/route.php` and `application/database.php`.
  During the install, `application/` itself also needs write access.
- If you know how, make `application/database.php` read-only after the install. JavCMS does not change it later.

### Check that private files are private

Open these addresses in your browser. Each one must show an error page (403 or 404),
**never** a file's content or a download:

- `https://your-domain/application/database.php`
- `https://your-domain/runtime/index.html`
- `https://your-domain/vendor/composer/installed.json`

The included `.htaccess` (Apache) and `web.config` (IIS) block these folders, and they block running any script
from the `upload/` folder. On Nginx or OpenLiteSpeed you must add the rules from
[../deployment/README.md](../deployment/README.md) yourself. If one of the addresses above shows content,
fix your server rules before you do anything else.

### Security headers

JavCMS sends its security headers with every page by itself. For static files (scripts, styles, pictures,
fonts, videos) the web server sends the header `X-Content-Type-Options: nosniff`, so a browser never runs
such a file as something else:

- **Apache** and **IIS:** the included `.htaccess` and `web.config` already do it. If you keep your own
  `.htaccess` from an older release, copy the block that contains `nosniff` from the new file into yours.
- **Nginx:** the example files in `deployment/` contain the line. Copy it if you wrote your own configuration.
- **OpenLiteSpeed:** it ignores this part of `.htaccess`. In WebAdmin, add
  `set X-Content-Type-Options nosniff` under **Header Operations** of your site.

To check: open your browser's developer tools (F12), tab **Network**, reload your home page, click a `.js` file
and look for `X-Content-Type-Options: nosniff` under the response headers.

## 6. Turn on only what you use

- **Public APIs and the receiving interface are off** after install. Turn one on only if you need it,
  and set its own password or key.
- **Submission API** (Advanced): only if an outside tool must push videos into your site.
- **Other API sources** (Advanced › Other import tools): only `http://` and `https://` addresses on the public
  internet are accepted. Local and private network addresses are refused. Add only sources you trust.
- Do not install themes, add-ons or code from people you do not trust.
- Paste ad network code only from real ad networks.

## 7. Use HTTPS

Turn on an SSL certificate in your hosting panel and use `https://` everywhere.
Without HTTPS, passwords travel in plain text. See [../INSTALL.md](../INSTALL.md), "HTTPS (SSL)".

**HSTS** (the `Strict-Transport-Security` header) tells browsers to open your site only over HTTPS.
JavCMS sends it by itself, only on HTTPS pages, with a safe default: **7 days, this domain only**.
If your certificate ever expires or you go back to HTTP, returning visitors can reach your site again
after at most 7 days. You do not need to change anything.

When your domain **and every sub-domain** (for example `mail.`, `cpanel.`, `www.`) work on HTTPS,
you can make it stronger. Open `application/common/util/SecurityHeaders.php` and change the line
`const HSTS = 'max-age=604800';` to:

- `const HSTS = 'max-age=31536000; includeSubDomains';` (one year, all sub-domains), or
- `const HSTS = 'max-age=63072000; includeSubDomains; preload';` only if you will also add your domain
  at hstspreload.org. Leaving that list takes months, so think twice.

Do not add a second HSTS header in your hosting panel, CDN or `.htaccess`. After an upgrade, make your change again,
because the new release brings the default file back.

## 8. Keep everything up to date

- **JavCMS:** watch GitHub and Telegram for new releases. Read [../UPGRADE.md](../UPGRADE.md) and back up first.
- **PHP:** use a PHP version that still gets security updates (PHP 8.1 or newer if your host offers it).
  JavCMS works with PHP 7.4 to 8.5.
- **Database:** use a maintained MySQL or MariaDB version.

## 9. Check your site regularly

- **Admins & backup › Security scan** checks your site files for changed or unknown PHP code,
  and can find script code that was injected into your content.
- **Overview** shows problems that need attention.
- Make backups and **download** them. A backup that only lives on the same server is lost when the server is lost.

## 10. Floods, crawlers and attacks

JavCMS protects itself without any extra service. Every protection below is on by default, with values high enough
that normal visitors and search engines never notice them, and works without Cloudflare. **Admins & backup › Speed &
protection** shows each one in plain words in its **Protection against floods** section, and lets you change the values.

What keeps the site up:

1. **No visitor causes a request to AVDB or xvidapi.** Imports run on their own schedule.
2. **A fast page cache.** Pages for visitors who are not signed in are kept 5 minutes and sent in about 2 ms.
   Thousands of visits per second cost almost nothing. The cache has a size limit, so a flood cannot fill your disk.
   A deleted or hidden video disappears at once, even under load.
3. **Query time limit (on).** Counts are made in the background, deep pages read prepared page maps
   ([MAINTENANCE.md](MAINTENANCE.md)), and every database query of a visitor page stops after a few seconds
   (5 seconds, 3 seconds above a million videos). The visitor then gets the last good copy of the page or a simpler
   page, never an error page.
4. **Turning visitors away when busy (on).** Only a few searches and deep pages are built at the same time ("slots").
   Visitors above that get the last good copy of the page, or a short "The site is busy" page that retries by itself
   after a few seconds. Video pages keep working during a search flood. A browser or bot that opens more pages at the
   same time than its own limit (one search or deep page and two other pages at once) gets the last good copy or the
   lighter page while the site is not busy; the busy page comes only while the site is really turning visitors away.
5. **Per-visitor request limits (on, high values), never one limit for the whole site.** Each visitor network (one
   IPv4 address, or one IPv6 /64 block) may load **300 searches, filtered lists or deep pages** and **2,400 other
   pages per minute** that are not in the page cache; cached pages never count. Above that it gets the cached copy,
   or "Too many pages in a minute from your network. Retrying in N s." One attacker cannot lock out everybody else,
   and visitors behind one shared proxy are never counted together (see "Behind a proxy" below).
6. **Search engines are never blocked** (below).
7. **A broken or hostile provider answer cannot empty or deface your site**: strict checks, allowed video hosts only,
   and a safety stop for takedowns ([IMPORT.md](IMPORT.md)).
8. **Database full**: imports stop before your database limit, with a readable message.

### Search engines are never blocked

If search engines cannot crawl your site, they cannot list it, and you get no visitors from them. So JavCMS never
limits or turns away a **checked** search engine (no busy page, no "too many pages"), also not in Under attack mode:

- **Googlebot** (and Google's other crawlers), **Bingbot**, **YandexBot**, **DuckDuckBot** and **Applebot**.
- A crawler counts as checked when its address is in the IP list the search engine publishes (Google, Bing,
  DuckDuckGo, Apple), or when a reverse DNS lookup of its address gives the search engine's own host name and that
  name points back to the same address (Google, Bing, Yandex, Apple).
- Housekeeping downloads the published IP lists once a day and keeps the last good list when a download fails.
  The package ships a copy of the four lists (`application/data/crawlers/`, with the day they were fetched), so the
  search engines are recognised from the first request; the installer and **Finish upgrade** download the current lists
  at once. **Speed & protection › Protection against floods** shows the checked search engines, when each list was last
  refreshed, and "IP list shipped with JavCMS" for a list that is still the shipped copy.
- On a site that is not busy, a checked search engine's video, watch and list pages also keep a place of their own, so
  its own slow pages (big tag or category pages) do not turn them into lighter pages.
- A search engine renders up to two pages at the same time; a page that finds both places taken waits up to half a
  second. Right after a restart of a big site, while the database is cold, two slow filter pages can hold both places
  longer than that, and a heavy page that comes then (a filter page, or a list page after page 50) gets the lighter
  page: HTTP 200 without "noindex", with the newest videos and a "Try again" link. The search engine gets the full
  page on a later visit (0-3 of 595 pages in the first minute of our runs with 7.1 million videos and Googlebot at
  10 pages per second; see [HOSTING.md](HOSTING.md), "Known slower cases").
- Somebody who only writes "Googlebot" in the browser name is treated like every other visitor: the name alone
  proves nothing.

Search engines still follow your robots.txt (see [SEO.md](SEO.md)); it sets no crawl delay, so they can crawl a big
catalog at their own pace.

### Under attack mode

A switch on **Speed & protection › Protection against floods**, **off by default**. Turn it on only while your site is
attacked and the normal limits are not enough:

- per visitor network **30 searches, filtered lists or deep pages and 240 other pages per minute**,
- half the slots for searches and filters,
- a query time limit of at most 3 seconds.

Checked search engines stay allowed. Cached pages still work for everybody. Turn it off again when the attack is over
(the page shows since when it is on). This is **not** Cloudflare's "I'm Under Attack" mode: nobody gets a challenge
page, so importers, the automatic import callback and search engines keep working.

### Raise or lower the values

Open **Speed & protection › Protection against floods › Raise or lower the values**:

| Field | Default | Allowed |
|---|---|---|
| Searches, filters and deep pages per minute | 300 | 10 to 100,000 |
| Other pages per minute | 2,400 | 10 to 100,000 |
| Query time limit in seconds | 5 (3 above a million videos) | 1 to 600 |
| Under attack: searches, filters and deep pages per minute | 30 | 1 up to the normal value |
| Under attack: other pages per minute | 240 | 1 up to the normal value |

Leave a field empty to use the default; **Back to the defaults** empties them all. The limits cannot be switched
off (0 is not accepted). Raise the values when many real visitors share one address (a school, an office, a mobile
network) and you see 429 answers for them under **Details › Pages refused (too many requests) in the last hour**; lower them on a small server
that slows down under crawlers you do not want. New values apply to the next requests. Without the admin page, the
same values are the keys `budget_heavy`, `budget_light`, `stmt_limit`, `under_attack` (0 or 1),
`attack_budget_heavy` and `attack_budget_light` in the `app` part of `application/extra/maccms.php` (empty = default).

**The honest limit:** no website code can stop a network flood of gigabits per second. That needs Cloudflare
(free plan, below) or the DDoS protection of your host, and a hidden server address.

### Behind a proxy: "All visitors appear to come from ..."

If your site runs behind a proxy on the same server or network (nginx in front of Apache, a hosting panel proxy,
a load balancer, Cloudflare Tunnel), JavCMS may see one address for all visitors. It then switches the per-network
limits **off** for that address (so your visitors are never blocked together) and shows a red notice on Speed &
protection: **"All visitors appear to come from <address>: set the proxy header."**

- Click **Trust this proxy (<header>)**. JavCMS trusts that proxy and reads the visitor address from the header your
  proxy really sends. Only a proxy on this server or your private network can be trusted.
- If visitors reach your site through **cloudflared** (Cloudflare Tunnel) on this server, turn on the
  **Cloudflare Tunnel switch** instead.

**"<address> sends page views with many different browsers (a CDN or firewall in front of the site?)"** is a yellow
notice for a public address that sends most of the pages that are not cached, with more than 20 different browser names
in 10 minutes. Its limits stay per address (300 searches, filters or deep pages and 2,400 other pages per minute);
for 10 minutes it may build more pages at the same time, so the visitors behind a real CDN are not slowed down. If that
address really is your CDN or firewall and your visitors get "Too many requests", add it by hand to `trusted_proxies`,
and the header it sends (for example `X-Forwarded-For`) as `proxy_header`, in `application/extra/public_request_guard.php`.
The **Trust this proxy** button only trusts proxies on this server or your private network, on purpose. If you do not
know the address, do nothing: the notice goes away by itself after a day.

### Cloudflare in 10 minutes (optional, free plan)

1. Create a free Cloudflare account, add your domain and change the nameservers at your domain registrar as
   Cloudflare tells you. Wait until Cloudflare says the site is active.
2. **DNS:** the record of your site has the orange cloud (Proxied).
3. **SSL/TLS:** choose **Full (strict)** (your server needs its own certificate, see section 7).
4. Open **Speed & protection › Details › Cloudflare** in your admin. It shows these three rules with **your real
   admin address** and install folder. Copy them from there. For a site in the main folder with the admin file
   `javcms.php` they read:
   - **Cache rule** (Rules › Cache Rules; "Eligible for cache", Edge TTL "Use cache-control header if present"):

     ```
     (http.request.method eq "GET" and not starts_with(http.request.uri.path, "/javcms.php") and not starts_with(http.request.uri.path, "/api.php") and not http.request.uri.path contains "/user" and not http.request.uri.path contains "/ajax" and not http.request.uri.path contains "/jcx" and not http.cookie contains "user_id=" and not http.cookie contains "PHPSESSID" and not http.cookie contains "avdb_ui_lang" and not http.cookie contains "jv_theme" and not http.cookie contains "jcxp")
     ```

   - **Rate limiting rule** (Security › WAF › Rate limiting rules; 20 requests in 10 seconds per IP, then block):

     ```
     (starts_with(http.request.uri.path, "/search") or http.request.uri.query contains "wd=")
     ```

   - **Skip rule** for the automatic import callback (Security › WAF › Custom rules, action **Skip**, all remaining
     custom rules and rate limiting):

     ```
     (http.request.uri.path eq "/api.php/avdbschedule/index")
     ```

   If you renamed `javcms.php` (section 1), the admin shows your own name in the cache rule: always copy the rules from
   Speed & protection, not from this page.
5. **Leave "I'm Under Attack" off.** It breaks importers, the automatic import callback and search engines.
   The rules above are enough. During an attack use JavCMS's own **Under attack mode** (above) instead.
6. Without a Cloudflare purge token, a deleted page can still show at Cloudflare for up to 5 minutes.

JavCMS reads the real visitor address from Cloudflare only when the request really comes from Cloudflare's network
(or through the Tunnel switch). A fake header from anybody else is ignored.

### Lock the server to Cloudflare (origin lock)

Attackers who know your server's real address can go around Cloudflare. After Cloudflare is on:

1. Allow web traffic (ports 80 and 443) **only from Cloudflare's addresses**. Speed & protection › Details shows the
   ready snippets with the current list (https://www.cloudflare.com/ips/):
   - **Firewall (ufw)** on your VPS: one `ufw allow proto tcp from <range> to any port 80,443` line per range, then
     `ufw deny 80/tcp` and `ufw deny 443/tcp`.
   - **Apache** (in the Directory block of the site or in `.htaccess`): `<RequireAny>` with one `Require ip <range>`
     line per range.
   - **nginx** (inside the server block): one `allow <range>;` line per range, then `deny all;`.
2. **Change the server's IP address** (ask your host, or create a new VPS from a snapshot), because the old address
   may already be known.
3. **Do not send email from this server.** Email headers show its address. Use your host's mail service or an
   email provider.

## If your site was hacked

1. Change all passwords: admin accounts, database user, hosting panel and FTP.
2. Run the **Security scan** and look at every file it reports.
3. Compare your files with a fresh copy of the same JavCMS release. Replace changed files.
4. Restore a clean database backup if your content was changed.
5. Change your admin address again (section 1).
6. Ask your host to check their logs, and ask for help on Telegram: https://t.me/avdb_api

Nobody from the JavCMS team will ever ask for your passwords. Never post passwords, database settings
or the content of `application/database.php` or `application/extra/` in a chat or an issue.
