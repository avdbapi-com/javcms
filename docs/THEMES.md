# Themes

JavCMS has 17 themes. All of them:

- work on phones, tablets and computers (one theme for all screens),
- start in dark mode, with a light/dark switch that remembers each visitor's choice,
- have a language menu with 17 languages,
- show your logo, your site icon, your legal pages and your ads,
- play videos in the provider's own player.

## The 17 themes

| Folder | Name | Look |
|---|---|---|
| `javcms-modern` | JAVCMS Modern | Coral gallery design. The default theme. |
| `jav` | JAV Classic | Warm black gradient, yellow highlights, cinema-seat watch page. |
| `jav-modern` | JAV Modern | Indigo gallery: navy panels, rounded cards, aurora hero. |
| `ember` | Ember | Black and orange tube design. |
| `ember-modern` | Ember Thermal (Modern) | A thermal-camera catalogue with a living heat effect and heat-coded cards. |
| `onyx` | Onyx | Near-black and orange, coverflow banner, underlined navigation, sidebar lists. |
| `onyx-modern` | Onyx Grid (Modern) | A bold ruled index with a 1-bit dither effect and hard-cut motion. |
| `velvet` | Velvet | Navy and rose-red night design, app-style phone layout with a bottom tab bar. |
| `velvet-modern` | Velvet Premiere (Modern) | A premiere-night cinema with velvet curtains and a following spotlight. |
| `honey` | Honey | Amber header, light grey page, rounded video cards, navy and gold accents. |
| `honey-modern` | Honey Nectar (Modern) | Liquid gold, a fanned card stack and honey-filled hexagons. |
| `neon` | Neon | Dark hot-pink app design with a pill search and a bottom tab bar on phones. |
| `neon-modern` | Neon Nocturne (Modern) | Rain on neon glass, tube-lit signs and a split-flap departures board. |
| `crimson` | Crimson | Red-bar video app design with a scrolling tab strip and overlay cards. |
| `crimson-modern` | Crimson Ink (Modern) | Two-colour manga print with speed lines, ink panels and page-turn transitions. |
| `topaz` | Topaz | Midnight-navy glass panels with gold accents and a cinematic hero slider. |
| `topaz-modern` | Topaz Prism (Modern) | A jewellery-salon look with crystal cards, gem-coloured categories and a command palette. |

Several themes are rebuilt from third-party themes; where each design comes from is described in the file
`LICENSE`, Part D. JAVCMS Classic (`javcms`), the default theme up to release 1.1.0, is not in this release: it used
a stylesheet and an icon font of third-party themes whose authors and licenses are not known. If your site still uses
it, see `UPGRADE.md`.

The **Modern** themes use more motion and effects. They turn heavy motion off for visitors who ask their
device for less motion, and they skip 3D background effects on slow or low-memory devices and in data-saving mode.

## Choose your theme

1. Open **Site & theme › Themes**.
2. Each theme has a preview picture. Click **Preview** to see your own site in that theme.
   Only you see the preview. Visitors keep the current theme.
3. Click **Stop preview** to go back.
4. Click **Use this theme** on the one you like. Visitors see the change right away.

## Let visitors choose (visitor theme switcher)

The visitor theme switcher is a small floating button. Visitors click it and pick a theme.
Their choice is kept in their browser.

1. Open **Site & theme › Site identity** and find the **Themes** card.
2. **Visitor theme switcher:** on or off. It is on in new installs and off on upgraded sites.
3. **Switcher button position:** bottom end (right) or bottom start (left).
4. **Themes offered to visitors:** tick the themes you want to offer.
   Leave all unticked to offer every theme. Your default theme is always offered.
5. Save.

First-time visitors always see your default theme.
Pages saved as static HTML files (an option in Page addresses) ignore the visitor's choice.

If you use a CDN with a "cache everything" rule: exclude your HTML pages from that rule,
or add the cookies `jv_theme`, `jv_theme_preview` and `avdb_ui_lang` to its cache key.
Otherwise one visitor's theme or language can be shown to others.

## Logo, icon and colours

- Your **logo** and **site icon** are set once in Site & theme › Site identity and show in every theme.
  See [SEO.md](SEO.md).
- The **brand colour** in Site identity colours the phone browser bar. Empty uses the theme's own colour.
- The Ember theme can show a separate logo on phones (the field appears while Ember is your theme).

## Add a theme of your own

You can copy another theme folder into `template/`. For your safety, JavCMS checks every theme folder.
A theme appears in the list only when:

1. it contains `info.ini` and `html/index/index.html`,
2. `info.ini` does not say `status = "draft"`,
3. the automatic safety scan finds no server-side files (such as `.php` files, `.htaccess`, or pictures that hide PHP code)
   and no dangerous code in its templates.

Themes that are not accepted are listed in the admin with the reason.
Only install themes from people you trust.

If the content column of your theme is transparent in light mode, give it an opaque background while the page has the
class `jcx-wp` (`html.jcx-wp`): the home masthead / takeover ad puts its art on the page background at 1400 px and wider.
The bundled themes do this in `static/template-ui/lowres.css` (section 8).

Advanced: to preview a theme only in your browser from the command line, run
`php tools/theme-preview-cookie.php <folder>` and set the printed value as the cookie `jv_theme_preview` for your site.

## Edit a theme

- **Theme files (advanced)** on the Themes page lets you look at a theme's HTML files (read-only).
- To change a theme, edit its files in `template/<folder>/` with your hosting File Manager or by FTP.
  Keep a copy of your changes: an upgrade replaces the theme files.
- Theme texts in all languages are in `template/<folder>/lang/<code>.json`.
- Click **Clear cache** in the admin after you change theme files.
