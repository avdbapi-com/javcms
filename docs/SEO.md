# Search engines (SEO)

JavCMS does the technical SEO work for you: clean page addresses, page titles, canonical links,
language links, structured data, a sitemap and a robots.txt file.
This guide shows the settings you should look at once.

All settings below have safe defaults. You do not have to change them to get started.

## 1. Site identity

**Site & theme › Site identity.**

**Name & home page**

- **Site name:** a short name, like "JavHub". It is added to the end of page titles.
  Keep it at 30 characters or less. A longer name makes every title long.
- **Home page title:** the title of your home page in Google, for example "JavHub: Japanese adult videos in HD".
- **Home page description:** one or two sentences, best 70 to 160 characters.
- The **Preview** shows how your home page may look in Google.
- **Domain:** your domain without `http://`, for example `example.com`.
- **Contact email:** shown in the footer and on the legal pages.

**Logo & site icon**

- **Logo:** shown in the header of every theme.
- **Site icon (favicon):** choose one:
  - **JavCMS icon** (the default),
  - **My icon:** click **Upload icon** and choose a square picture, best a 512 × 512 PNG
    (PNG, JPEG or WebP, 144 × 144 to 4096 × 4096 pixels, up to 2 MB),
  - **Each theme's own icon.**
- JavCMS makes every size for you: browser tabs, Google results and phone home screens.
  The preview shows the tab and phone icon on dark and light backgrounds.
- Use a logo or a letter, **not an explicit picture**. Google replaces explicit icons with a grey globe.
- **Brand colour:** the colour of the phone browser bar. Empty uses the theme's colour.
- Press **Save**. Google can take days or weeks to show a new icon.

If your site root is writable, JavCMS also updates `/favicon.ico`.
If not, click **Download favicon.ico** and upload it to your site root yourself.
Without the PHP GD extension, upload a square 512 × 512 PNG.

**Sharing**

- **Share picture:** shown when someone shares a page without its own video picture (home, categories, tags).
  Best 1200 × 630 pixels.
- **Your profiles:** links to your profiles (X, Telegram, Reddit and so on), one per line, starting with `https://`.

**Code & analytics** (second tab)

- **Google Analytics 4 ID:** paste your measurement ID (starts with `G-`). JavCMS adds Google's code.
- **Code in <head>** and **Code at the end of every page:** for other analytics or verification code.
- **Wait for consent:** code waits for the visitor's cookie consent.

## 2. Languages

**Site & theme › Languages.**

- **Default language:** the language visitors and search engines see first.
- **Languages visitors can choose:** the languages in the language menu of every theme.
- **Languages search engines may index:** video titles in other languages come from automatic translation.
  Untick languages you do not want in Google.
- **Home page in other languages:** your own home page title and description per language.
  If a language has no description of its own, its home page uses that language's home page title as the
  description, never your English text. Write a real description for each language you care about.

## 3. Search titles & descriptions

**Search engines (SEO) › Search titles & descriptions.**

At the top you see the **Search engine checklist** with 14 checks, for example:
a short site name, a home page title, your own icon, site verification, HTTPS addresses,
a sitemap that answers, legal pages with your own text, and compressed pages.
Each open item has a **Fix** link that takes you to the right setting.

**Titles** tab

- The tab starts with a **Home page** card: how your home page can look in Google.
  **Edit home page title** takes you to Site identity, where you set the title and description.
- **Add your site name to titles:** at the end, or no.
- **Separator:** the character between the page name and your site name.
- Previews of a video page, a category page and a tag page.
- **Custom patterns (advanced):** your own pattern per page type with placeholders such as the video title.
  Leave empty to use our pattern, which is translated into every language.
- **Your own titles for tags and actresses:** a title and description for a single tag or actress page.
- **Category titles:** edit each category in Videos › Categories. The **Search engines** section of the
  category editor has the SEO title and description and a live Google preview.
  Leave the fields empty to use the category name and your title pattern.

**What Google may show** tab

- **Let search engines show tag and actress pages** that have at least a minimum number of videos (default 10).
  On a big site the description of an actress page, or of a tag page the term map cannot prove exact, with a very
  common name (every word of it in more than 10,000 videos) does not start with the number of videos
  (1.1.0 wrote the exact number); the page can still be shown. This applies from size level 3 (1,000,000 published
  videos or more; Speed & protection › Details shows your site's level); below it, lists, totals and landing
  descriptions count as in the first 1.2.0.
- **Hide list pages after page:** 0 shows all pages.
- **Always hidden from search engines:** search results, filter combinations, member pages,
  comments and messages, password-protected videos, empty sections and hidden categories.
- **Hide the whole site from search engines:** only for a test copy. You must type `HIDE` to turn it on.
  Turn it off when your site goes live.

**Verify your site** tab

Paste the code that Google, Bing, Yandex or Naver gives you. The code alone or the whole tag both work.

- **Google:** Google Search Console › Add property › URL prefix › HTML tag.
- **Bing:** Bing Webmaster Tools. Easiest: use "Import from Google Search Console" there.
- **Yandex:** Yandex Webmaster › HTML meta tag.
- **Naver:** Naver Search Advisor › HTML tag.

### Hidden categories

When you hide a category (the **Hide** button in the category list, or the switch **Visible on the site**
in the category editor), it leaves the menus and the home page at once. Its category pages answer
**Page not found** (404) and tell search engines not to index them, so they drop out of Google over time.
You do not need to clear the cache. Show the category again and its pages come back.

Hiding a category does not delete its videos. AVDB imports keep using your hidden category
(see [IMPORT.md](IMPORT.md)), and it stays hidden.

## 4. Send your sitemap

Your sitemap is at:

```
https://your-domain/sitemap.xml
```

It lists your pages and videos, split into small files. JavCMS keeps it up to date by itself.

**Google:**

1. Open Google Search Console and choose your site.
2. Open **Sitemaps** in the menu.
3. Paste your sitemap address and press **Submit**.

**Bing:**

1. Open Bing Webmaster Tools and choose your site.
2. Open **Sitemaps**, press **Submit sitemap** and paste your sitemap address.

The **Verify your site** tab shows your sitemap address with a **Copy** button.

## 5. Sitemap & robots.txt

**Search engines (SEO) › Sitemap & robots.txt.**

**Sitemap** tab

- **Send translated pages to search engines:** one sitemap per language that search engines may index.
- **Add video details** (thumbnail, duration) to the sitemap.
- **Videos per sitemap file:** 1,000 to 50,000 (default 5,000). Smaller files are easier for slow servers.

**robots.txt** tab

Your `/robots.txt` is made for your domain and always contains your sitemap address. Choose:

- **Recommended:** search results and member pages are not crawled; everything else is.
- **Allow everything.**
- **Custom:** your own rules. To block your whole site you must type `BLOCK`.

**What search engines get** shows the exact file.
If you put your own `robots.txt` file into the site root, JavCMS uses its rules and adds your sitemap line.

**Deep pages** (every list, category, tag, actor and search result is reachable, down to the oldest video)

JavCMS has no page limits: a visitor can open page 10,000 of a big category, or the last page of a word search or of a
single-word tag that the term map proves exact; actor pages and the other tag pages number at most their first 10,000
videos and reach every older one through "Older" (see [MAINTENANCE.md](MAINTENANCE.md), "Search"). Search engines
do not need those pages, so the setting **Hide list pages after page** asks them not to index list pages after a
page number: **50 by default from 200,000 videos** (0 = index every page on smaller sites). The pages are still
served normally to visitors and crawlers (HTTP 200 with `noindex`); only their place in search results changes. Every
video stays in the sitemap. The recommended robots.txt also keeps crawlers away from search results, sorted lists
(`by=`) and the "Older" continuation links (`after=`, `cursor=`), so crawlers spend their time on your videos.
Set the number higher, or to 0, if you want deep pages indexed.

**IndexNow** tab

IndexNow tells Bing, Yandex, Naver and Seznam about new and changed videos right away. Google does not use it.
It is off by default.

1. Click **Turn on**. JavCMS makes a key and writes a small key file into your site root.
2. If your site root is read-only, click **Download key file** and upload it to your site root.
3. The tab shows the last answer, how many addresses were sent today and how many wait.

## 6. Page addresses and HTTPS

**Search engines (SEO) › Page addresses (URLs)** decides how links to videos and categories look.

- Choose this **once**, before your site gets traffic. Changing it later changes every address,
  and search engines need weeks to learn the new ones. The page warns you if your site is older than 7 days.
- Set **Preferred canonical protocol** to **HTTPS** when your site works with HTTPS.

## 7. Legal & age

**Site & theme › Legal & age.**

- **Pages in your footer:** terms, privacy, DMCA, cookies and 2257. Untick a page to hide it.
  Our texts are examples, not legal advice. Click **Edit text**, read them and change them for your site and country.
- **Your details:** site operator, copyright (DMCA) agent and address, custodian of records (2257).
  These fill the placeholders in the texts.
- **Adult content labels:** the **RTA label** that parental-control filters read (on by default).
- **18+ question:** ask visitors to confirm they are 18 or older (off by default).
  Search engines are not asked. Some countries require real age checks that this simple question does not provide.
- **Cookie notice:** Automatic (shown only when something waits for consent), On or Off.

## 8. Good habits

- Write a real home page title and description.
- Use your own site icon.
- Verify your site with Google and send your sitemap.
- Keep the site name short.
- Do not change page addresses after launch.
- Import videos with good titles. Choose only the languages you want in Google.
