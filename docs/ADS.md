# Ads

All ads live in one place: **Site & theme › Ads & tracking code**.
The page has 7 tabs: **Overview**, **My ads**, **Places**, **Video ads**, **Network & files**, **Stats** and **Settings**.

Ads work the same way in all 17 themes.

## The main switch

At the top of the page you see **Ads on your site: On** or **Off**.
Off hides every ad on your site at once. Your ads stay saved.
With the switch off, your pages carry no ad code at all, so they are as light as a site without ads.
You find the same switch in the **Settings** tab.

## Start here: the 58 example ads

JavCMS comes with 58 example ads in 19 formats. They show you what each format looks like on your own pages.

1. Open the **Overview** tab.
2. Click **Install example ads**.
   The examples are installed **off**. Visitors never see them. Only you see them in preview.
3. Click **See them on my site**. Your site opens in preview mode with the examples drawn in their places.
   A small **Preview** panel shows how much of the screen is covered by ads and if the page moves while it loads.
   (In preview, all examples are stacked at once. Your real pages show far fewer ads.)
4. Click **Exit preview** in the panel when you are done.

The **Get started** list on the Overview tab follows the same path:
install examples, see them, add your first ad, switch it on, check on a phone.

### Use an example as your own ad

1. Open **My ads** and click the chip **Examples**.
   (The other chips, such as **Your ads**, **On** and **Off**, count only your own ads.
   Examples are always off, so the bulk actions on the Examples chip offer only **Delete**.)
2. Pick an example and click **Duplicate**. You get a copy that is **off**.
3. Open the copy. Replace the picture, the link or the code with your own.
4. Choose its place, check it, and click **Save and switch on**.

**Remove example ads** (Overview tab) deletes all examples again. Your own ads stay.

## Add your own ad in 3 steps

Click **Add an ad**.

**Step 1. What do you want to show?** Choose a card:

| Card | What it is |
|---|---|
| My image or GIF | Upload a picture and add a link. A second, smaller picture for phones is optional. |
| Code from an ad network | Paste the code your ad network gave you. |
| Video before the video | A short video plays before the main video (pre-roll). |
| Card in video lists | A card that looks like a video card, marked "Ad". |
| Sticky bar | A small bar that stays at the bottom of the screen. |
| Popup / popunder (network) | Opens the advertiser's page in a new tab. Your network does this. |
| Notification-style box | A small message box on the page. Works on iPhone too. |
| Text link | A labelled link in the menu, under the player or in the footer. |

Under **More formats** you find: expandable banner, floating corner / slide-in, full-screen between pages,
overlay / exit intent, side rails, home masthead / takeover, recommendation widget / carousel / collection,
HTML5 (zip), video in the page, sponsored post and web push (network).
The art of the home masthead / takeover shows in the page background beside the content column (1400 px and wider); in
light mode the content column keeps the theme's light background, so its text stays readable.

The link **Which ad formats can my site show?** lists every format with its common names,
and also the ones that are not possible on a website (TV apps, search ads, in-app ads and so on).

**Step 2. Where should it appear?** Your own page opens in a frame.
Click a **place** on it, or choose from the list. Places that do not fit this ad are greyed out.
Choose the devices (phones, computers or both) and, under **More options**, the pages, schedule and targeting.

**Step 3. Check and switch on.** You see your real pages with only this ad, on a computer and a phone.
Only you see this preview. A script-only ad, such as a popunder, has no picture on your pages:
step 3 then says so, and the frames show your pages without it. Then click:

- **Save (off)** to keep it switched off, or
- **Save and switch on** to show it to visitors now.

## Files you can upload

| File | Rules |
|---|---|
| Picture | JPG, PNG, GIF or WebP, up to 2 MB. The size is read from the picture. |
| Card picture | 16:9, up to 2 MB. |
| Small picture (icon) | 64 × 64, optional. |
| Video | mp4 or webm, up to 20 MB. Or a link to an mp4, webm or m3u8 file (starting with https://). |
| HTML5 ad | A zip up to 1 MB with an `index.html` inside. Its scripts run in a closed box. |

Best for video: mp4 (H.264), under 2 MB and under 30 seconds. The upload limit is 20 MB.
Uploading a 20 MB video needs `upload_max_filesize` and `post_max_size` of at least 20M in your PHP settings.

**Video only in the page.** A sticky bar, corner box, notification box, side rail or overlay must use a picture
(JPG, PNG, WebP or GIF). A video file there is refused when you save. Videos play only in a slot in the page:
video in the page, out-stream, or the phone video of the home takeover. Video ads before the main video
are set up in the **Video ads** tab.
Uploaded files are stored in `upload/jcx/`.

## Places

The **Places** tab lists the spots on your pages where ads can go:

| Place | Where |
|---|---|
| Top of page | Above the content on every page |
| Home: big banner on the first screen | Home page only |
| Under the player | Watch page |
| Video page: above related videos | Video page |
| Beside the player | Watch page, on wide screens |
| Inside video lists | Between video cards |
| Home: between rows | Between the home page rows |
| Above the footer | Every page |
| Side rails (wide screens) | Left and right of the page |
| Sticky bar | Bottom of the screen |
| Pop-ups and corner boxes | Boxes and full-screen ads |
| Video player | Video ads before the video |
| Top of search results | Search page |
| Inside articles | Article pages |
| List sidebar | Category pages that have a sidebar |
| Code in the page head | Network code, no visible box |
| Code at the end of the page | Network code, no visible box |
| Before comments | Above the comments |

Choose **Computers** or **Phones** above the preview to see the places as they appear on that kind of screen.
On a narrow screen the tab starts with **Phones**.

For each place you see if it **works in your theme**, is **shown nearby**, or is **not in this theme**.
Switching a place off hides all its ads. For lists you can set "every how many cards" and "most per page".

**Advanced: change where a place goes.** Only if a place sits in the wrong spot of your theme.
You enter a simple page element such as `#main-content` and the position. **Test on my site** shows the result.

## Video ads (pre-roll)

Open the **Video ads** tab.

Your videos play in the video provider's own player. That is why video ads play **before** the video.
Ads during or after the video are not possible. (Old ads of that kind show "Cannot play". Delete them.)

What you can set:

- **What to play:** your own video file, or **VAST links** from your ad network (one per line, up to 5;
  if the first has no ad, the next one is tried). **Test tag** checks a VAST link.
- **Skip after:** 5 seconds, 10 seconds, or no skip (at most 15 seconds).
- **Bumper:** a 6-second ad that cannot be skipped.
- **Second ad:** play a second short ad right after the first (2 at most).
- **Start:** when the visitor presses Play (recommended), or automatically and muted.
- **How often:** before every video (the default), or once per 30 minutes, once per hour, once per day.
- **Watch a short video to hide ads** (rewarded video): visitors who watch it see no ads
  for 1 to 24 hours. You choose the hours and the button text. The ads on the page where they watched it are
  hidden at once (the page does not jump); from the next page they are gone.

**Install example video ads** and **Preview on a watch page** show how it looks.
After the ad, the main video starts in the provider's player.

Tips:

- Use mp4 (H.264) files under 2 MB and under 30 seconds.
- WebM-only ads do not play on iPhones before iOS 17.4: add an mp4 file too.
- If an ad cannot load, another of your video ads is tried. The main video always starts within about 6 seconds.
- On slow connections that the browser reports (Chrome and other Chromium-based browsers, mostly Android), your own files (uploaded to your site) are tried first.
- Starting on Play keeps Chrome from blocking your ads and allows sound.
- Visitors with an ad blocker do not see network (VAST) ads. Your own files are seen more often.
- Your video provider may show its own ad too. Two ads in a row make visitors leave.

## Network & files

Use this tab for code from ad networks.

1. Click **Add network code**.
2. Choose your **Ad network**. The admin shows which networks say they accept adult sites.
   Approval is always the network's decision.
3. Choose **What the network gave you** (a preset). JavCMS fills in the rest.
4. Paste the code exactly as you got it. Do not change it.
5. Choose where the code goes: **in the page head**, **at the end of the page**, or **in a place** (for banners).

Good to know:

- Use **one popunder network only**. Two popunders earn less and can break each other.
  The admin warns you when two compete.
- **Web push** needs HTTPS. Only one push network can own your site root.
- For **ExoClick**, copy the zone number and class from ExoClick "Sites & Zones". JavCMS writes the code.

**Root files.** Some networks ask for a file at the top of your site:

- **ads.txt**: paste the lines from your network, one per line.
- **Push worker file**: the file name and text from your push network.
- **Verification file**: the file name and text from your network.

JavCMS writes the file into your site root. If the root is not writable, click **Download file**
and upload it yourself. Root files work only when JavCMS runs at the top of your domain, not in a subfolder.

**Site-wide code.** Analytics and other code that runs on every page lives in
Site & theme › Site identity › **Code & analytics**. The card **Analytics and tracking code** at the end of
the Overview tab takes you there. From the ads page you can move a code from there into an ad.

**Old ad files** from older versions are found automatically. **Import as code ads** turns them into ads that are off.

## Who sees an ad, and how often

Open **More options** when you add or edit an ad:

- **Pages:** all pages, or only home, category lists, video page, watch page, search, articles, other pages, not found page.
- **Show from / Show until**, **weekdays** and **hours** (your site's time zone).
- **Languages:** only for visitors who use certain site languages.
- **Categories:** only with videos of certain categories.
- **Countries:** two-letter codes such as US, GB, DE. Start with `!` to hide it in these countries.
  This needs a country header from your server (Cloudflare sends one). Choose it in **Settings**.
- **Who sees it:** everyone, only visitors who are not logged in, or everyone except VIP members.
- **Share:** when several ads use the same place, how often this one shows.
- **How often:** let your network decide, a safe default, or your own limit per visitor.
- **What makes it appear:** after a few seconds, after scrolling, or when the mouse leaves the page (computers).
- **After a visitor closes it:** hide it until the browser is closed, or for 24 hours.

## How ads behave on your pages

JavCMS keeps your pages fast and usable, whatever ads you add:

- **No empty boxes.** An ad in the first screen appears together with its picture. It does not wait for the
  visitor to tap or scroll. Ads load after your page and never become its largest element,
  so the first view of your page is not slower with ads.
- **Space is kept.** The place of an ad is reserved, so the page does not jump when the ad arrives.
- **Menus first.** While a theme menu, drawer or bottom sheet is open, the sticky bar, corner boxes and side rails
  hide. They come back when the menu closes.
- **Phones.** Corner boxes and slide-up boxes leave the corner of the theme switcher free. They wait while
  the cookie notice, the player or a menu is in the way. A phone shows one fixed box at a time,
  and at most one ad inside the content per screen height.
- **The visitor's language.** Texts that belong to the ad system (for example the buttons of a carousel,
  the rewarded-video texts and the push-notification example) follow the language of the page.
  The pictures and texts of your own ads stay as you made them.
- **Limits of pasted code.** Code from an ad network that draws its own picture later, or its own fixed box,
  is drawn by the network. JavCMS cannot reserve space for it or move it.

## Stats

The **Stats** tab shows views, seen, clicks, CTR and closes, and for video ads also starts, completions, skips and errors.
Choose 7 or 30 days. **Top 5 ads** shows your best ads.

Numbers are counted in your visitors' browsers. Ad blockers and bots are not counted.
No visitor data is stored, only daily totals. For money, see your network's report.

## Settings

| Setting | What it does |
|---|---|
| Ads on your site | The main switch. |
| Members see no ads | Logged-in visitors see no ads. |
| VIP groups without ads | Members of the ticked groups see no ads. |
| Count views and clicks | Turns the stats on or off. |
| Country from this server header | Which header tells the visitor's country (for example Cloudflare's). |
| Wait for consent | Network code waits until your consent tool calls `JCX.consent(true)`. |
| Safe limits | Keeps your site inside Chrome's ad rules. Leave it on. |
| Reset stats | Deletes all counted views and clicks. |
| Remove all ads data | Deletes all ads, places, stats and root files. Your uploaded files in `upload/jcx/` stay. |

The **Health check** (Check now) tells you if your pages include the ad code, if a cache serves an old ad file,
if a place is missing in your theme, and if ads compete for the same spot.
When the main switch is off it says "Ads are off: your pages carry no ad code".
**Too many ads on** a page type means the ad list for that kind of page is over its size limit:
switch some ads off or show them on fewer pages.

### Why "Safe limits" matters

Chrome blocks **all** ads on sites that annoy visitors. With safe limits on, JavCMS keeps you inside the rules, for example:

- at most 2 cards in a video list per page, and one card after at least 6 video cards (8 is suggested),
- full-screen ads only when a visitor clicks a link to another page of your site, never when a page opens,
- no countdown full-screen ads, no popunder on play or close buttons,
- on phones, at most one ad inside the content per screen height.

Formats that can get your ads filtered are marked **Can get filtered**. Google's
**Ad Experience Report** and **Abusive Experience Report** (links in Settings) tell you if Chrome sees a problem.

## Who may manage ads

In Admins & backup › Admin accounts you can give each admin role only some ads rights,
for example "Switch ads on or off" without "Delete ads".
Pasting HTML or JavaScript code needs the separate right **May paste HTML / JavaScript code**.

## Troubleshooting

| Problem | What to check |
|---|---|
| My ad does not show | Is the main switch on? Is the ad switched on in My ads? Is its place switched on? Do device, pages, schedule, language and country fit? |
| It shows in preview but not for visitors | Click **Clear cache** in the admin top bar, or wait up to 1 hour. Run the **Health check**. |
| The health check says a cache serves an old file | Your CDN or cache keeps an old copy of `static/jcx/c.json` (or of a `c-<page>.json` file next to it). Exclude `/static/jcx/` from caching. |
| The health check says "Too many ads on" a page | Switch some ads off, or limit them to fewer pages under **More options**. |
| "Video files can only play in a page slot" when I save | Sticky bars, corner boxes, notification boxes, side rails and overlays need a picture. Use a JPG, PNG, WebP or GIF file, or choose a place in the page for the video. |
| A sticky bar or corner box is not visible while a menu is open | That is on purpose: ads step aside while a theme menu is open and come back when it closes. |
| A pasted code stops working | Choose "How the code loads" › **In a box**, or ask your network for a code without `//` comments. |
| I cannot see my ads | Ad blockers hide ads. Try a private window without extensions. Members and VIP groups may be set to see no ads. |
| A "Code" ad cannot be edited | Your admin role needs the right **May paste HTML / JavaScript code**. |
