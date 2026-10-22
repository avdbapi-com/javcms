# Third-party components in `static/vendor/fx/`

Everything in this folder is served locally; no file is fetched from a CDN at runtime. Each component keeps its licence file next to it. Versions are pinned exactly (the newest release that was at least 14 days old on 2026-10-10). Only permissive licences are accepted: MIT, ISC, BSD/0BSD, Apache-2.0, Unlicense, and SIL OFL 1.1 for fonts. Machine-readable inventory with SHA-256, sizes and npm tarball integrity: `fx-manifest.json`.

## JavaScript libraries

| Component | Version | Licence | Copyright | Licence file(s) | npm tarball |
|---|---|---|---|---|---|
| [ogl](https://github.com/oframe/ogl) | 1.0.11 | Unlicense | public domain dedication (Unlicense); author Nathan Gordon | `ogl@1.0.11/LICENSE.txt` | `ogl@1.0.11` |
| [motion](https://motion.dev) | 13.4.4 | MIT | Copyright (c) | `motion@13.4.4/LICENSE.md`, `motion@13.4.4/LICENSE-framer-motion.md` | `motion@13.4.4` |
| [anime.js](https://animejs.com) | 4.5.0 | MIT | Copyright (c) | `animejs@4.5.0/LICENSE.md` | `animejs@4.5.0` |
| [lenis](https://github.com/darkroomengineering/lenis) | 1.3.26 | MIT | Copyright (c) | `lenis@1.3.26/LICENSE.txt` | `lenis@1.3.26` |
| [Swiper](https://swiperjs.com) | 14.2.0 | MIT | Copyright (c) | `swiper@14.2.0/LICENSE.txt` | `swiper@14.2.0` |
| [Embla Carousel](https://www.embla-carousel.com) | 8.6.0 | MIT | Copyright (c) | `embla-carousel@8.6.0/LICENSE.txt` | `embla-carousel@8.6.0` |
| [Embla Carousel Autoplay](https://www.embla-carousel.com/plugins/autoplay/) | 8.6.0 | MIT | Copyright (c) | `embla-carousel-autoplay@8.6.0/LICENSE.txt` | `embla-carousel-autoplay@8.6.0` |
| [Splitting](https://splitting.js.org) | 1.1.0 | MIT | Copyright (c) | `splitting@1.1.0/LICENSE.md` | `splitting@1.1.0` |
| [vanilla-tilt.js](https://micku7zu.github.io/vanilla-tilt.js/) | 1.8.1 | MIT | Copyright (c) | `vanilla-tilt@1.8.1/LICENSE.txt` | `vanilla-tilt@1.8.1` |
| [Atropos](https://atroposjs.com) | 2.0.2 | MIT | Copyright (c) | `atropos@2.0.2/LICENSE.txt` | `atropos@2.0.2` |
| [tsParticles slim](https://particles.js.org) | 3.9.1 | MIT | Copyright (c) | `tsparticles-slim@3.9.1/LICENSE.txt` | `@tsparticles/slim@3.9.1` |
| [lottie-web (light SVG player)](https://github.com/airbnb/lottie-web) | 5.13.0 | MIT | Copyright (c) | `lottie-web@5.13.0/LICENSE.md` | `lottie-web@5.13.0` |
| [flubber](https://github.com/veltman/flubber) | 0.4.2 | MIT | Copyright (c) | `flubber@0.4.2/LICENSE.txt` | `flubber@0.4.2` |
| [canvas-confetti](https://github.com/catdad/canvas-confetti) | 1.9.4 | ISC | Copyright (c) | `canvas-confetti@1.9.4/LICENSE.txt` | `canvas-confetti@1.9.4` |
| [CountUp.js](https://inorganik.github.io/countUp.js/) | 2.10.1 | MIT | Jamie Perkins (author per package.json; the licence text has no copyright line) | `countup.js@2.10.1/LICENSE.md` | `countup.js@2.10.1` |
| [@formkit/auto-animate](https://auto-animate.formkit.com) | 0.10.0 | MIT | Copyright 2022 FormKit Inc. | `auto-animate@0.10.0/LICENSE.txt` | `@formkit/auto-animate@0.10.0` |
| [simplex-noise](https://github.com/jwagner/simplex-noise.js) | 4.0.3 | MIT | Copyright (c) | `simplex-noise@4.0.3/LICENSE.txt` | `simplex-noise@4.0.3` |
| [Zdog](https://zzz.dog) | 1.1.3 | MIT | Copyright (c) | `zdog@1.1.3/LICENSE.txt` | `zdog@1.1.3` |
| [Rough Notation](https://roughnotation.com) | 0.5.1 | MIT | Copyright (c) | `rough-notation@0.5.1/LICENSE.txt` | `rough-notation@0.5.1` |
| [morphicons](https://github.com/guillermolg00/morphicons) | 1.7.1 | MIT | Copyright (c) | `morphicons@1.7.1/LICENSE.txt` | `morphicons@1.7.1` |
| [NumberFlow (vanilla custom element)](https://number-flow.barvian.me) | 0.6.2 | MIT | Copyright (c) | `number-flow@0.6.2/LICENSE.md`, `number-flow@0.6.2/LICENSE-esm-env.txt` | `number-flow@0.6.2` |

**Code from other packages inside the bundles**

| Package | Version | Licence | Where | Licence file |
|---|---|---|---|---|
| framer-motion | 13.4.4 | MIT | inside motion@13.4.4/motion.min.js and motion-mini.min.js | `motion@13.4.4/LICENSE-framer-motion.md` |
| motion-dom | 13.4.4 | MIT | inside motion@13.4.4 ES module bundles | `motion@13.4.4/LICENSE.md` |
| motion-utils | 13.3.0 | MIT | inside motion@13.4.4 ES module bundles | `motion@13.4.4/LICENSE.md` |
| esm-env | 1.2.2 | MIT | a few bytes inside number-flow@0.6.2/number-flow.esm.min.js | `number-flow@0.6.2/LICENSE-esm-env.txt` |

## Icon sets

| Set | Version | Licence | Copyright | Sprites | Licence file |
|---|---|---|---|---|---|
| [Lucide](https://lucide.dev) | 1.48.0 | ISC | Copyright (c) | `sprite.svg` | `lucide@1.48.0/LICENSE.txt` |
| [Phosphor Icons](https://phosphoricons.com) | 2.1.1 | MIT | Copyright (c) | `sprite-regular.svg`, `sprite-light.svg`, `sprite-bold.svg`, `sprite-fill.svg`, `sprite-duotone.svg` | `phosphor-icons@2.1.1/LICENSE.txt` |
| [Tabler Icons](https://tabler.io/icons) | 3.48.0 | MIT | Copyright (c) | `sprite-outline.svg`, `sprite-filled.svg` | `tabler-icons@3.48.0/LICENSE.txt` |
| [Remix Icon](https://remixicon.com) | 4.8.0 | Apache-2.0 | Remix Design (the Apache-2.0 text carries no copyright line) | `sprite-line.svg`, `sprite-fill.svg` | `remixicon@4.8.0/LICENSE.txt` |

Remix Icon is pinned to **4.8.0**, the last release published under Apache-2.0. From 4.9.0 (January 2026) the files ship under the custom "Remix Icon License v1.0" (not an OSI licence) although `package.json` still says Apache-2.0. Do not upgrade it.

The `brand-*` symbols (Telegram, X, Facebook, Reddit, WhatsApp share marks; Lucide sprite uses the Tabler drawings) are trademarks of their owners. Use them only to link to or share on that service.

## Fonts (SIL Open Font License 1.1)

Self-hosted woff2 files from the Fontsource npm packages; the font files are unchanged, only the CSS was rewritten (plain family names, `format('woff2')`).

| Family | Copyright (from OFL.txt) | Subsets | Licence file |
|---|---|---|---|
| Unbounded | Copyright 2022 The Unbounded Project Authors (https://github.com/googlefonts/unbounded) | latin, latin-ext, vietnamese, cyrillic, cyrillic-ext | `fonts/unbounded@5.3.0/OFL.txt` |
| Space Grotesk | Copyright 2020 The Space Grotesk Project Authors (https://github.com/floriankarsten/space-grotesk) | latin, latin-ext, vietnamese | `fonts/space-grotesk@5.3.0/OFL.txt` |
| Syne | Copyright 2019 The Syne Project Authors (https://gitlab.com/bonjour-monde/fonderie/syne-typeface) | latin, latin-ext, greek | `fonts/syne@5.3.0/OFL.txt` |
| Bricolage Grotesque | Copyright 2022 The Bricolage Grotesque Project Authors (https://github.com/ateliertriay/bricolage) | latin, latin-ext, vietnamese | `fonts/bricolage-grotesque@5.3.0/OFL.txt` |
| Fraunces | Copyright 2020 The Fraunces Project Authors (github.com/undercasetype/Fraunces) | latin, latin-ext, vietnamese | `fonts/fraunces@5.3.0/OFL.txt` |
| Playfair Display | Copyright 2017 The Playfair Display Project Authors (https://github.com/clauseggers/Playfair-Display), Reserved Font Name "Playfair Display" | latin, latin-ext, vietnamese, cyrillic | `fonts/playfair-display@5.3.0/OFL.txt` |
| Tilt Neon | Copyright 2019 The Tilt Project Authors (https://github.com/googlefonts/Tilt-Fonts) | latin, latin-ext, vietnamese | `fonts/tilt-neon@5.3.0/OFL.txt` |
| Orbitron | Copyright 2018 The Orbitron Project Authors (https://github.com/theleagueof/orbitron) | latin | `fonts/orbitron@5.3.0/OFL.txt` |
| Oswald | Copyright 2016 The Oswald Project Authors (https://github.com/googlefonts/OswaldFont) | latin, latin-ext, vietnamese, cyrillic, cyrillic-ext | `fonts/oswald@5.3.0/OFL.txt` |
| Lexend | Copyright 2019 The Lexend Project Authors (https://github.com/googlefonts/lexend) | latin, latin-ext, vietnamese | `fonts/lexend@5.3.0/OFL.txt` |
| Manrope | Copyright 2019 The Manrope Project Authors (https://github.com/sharanda/manrope) | latin, latin-ext, vietnamese, cyrillic, cyrillic-ext, greek | `fonts/manrope@5.3.0/OFL.txt` |
| Chakra Petch | Copyright 2018 The Chakra Petch Project Authors (https://github.com/m4rc1e/Chakra-Petch.git) | latin, latin-ext, vietnamese, thai | `fonts/chakra-petch@5.3.0/OFL.txt` |

## Original JavCMS assets (JavCMS Community License 1.0, part of the JavCMS project)

- `lottie-anims/*.json` (6 Lottie animations, generated by JavCMS, no third-party animation data)
- `svg-anims/*.svg` (6 CSS-animated SVGs)
- `fonts/fallbacks.css`, `icon-concepts.json`, `fonts/fonts.json`, `*/icons.json`, `fx-manifest.json`

**Re-packaged copies outside this folder.** `static/template-ui/auto-icon/sprite-<family>.svg` (the auto-icon system) repackages glyphs from the four icon sets above (Lucide 1.48.0 ISC, Phosphor 2.1.1 MIT, Tabler 3.48.0 MIT, Remix Icon 4.8.0 Apache-2.0) into `ai-*` symbols with rounded coordinates, and `static/template-ui/auto-icon/morph-nodes.json` carries Lucide 1.48.0 icon-node geometry (ISC) for morphicons. The same licences apply; details in `static/template-ui/auto-icon/THIRD-PARTY.txt`. Plates (`pl-*`) and avatar patterns (`ai-pat-*`) in those sprites are original JavCMS art (JavCMS Community License 1.0).

## Changes made to third-party files

- **Copied unchanged** from the npm tarball: the UMD/IIFE and official minified builds listed with "copied" in `fx-manifest.json`, all CSS, all woff2 files.
- **Trailing `sourceMappingURL` comment removed** (the `.map` files are not shipped): `lenis.min.js`, `swiper-bundle.min.js`, `atropos.min.js`, `atropos.esm.min.js`.
- **Minified with esbuild 0.28.2** (ES2020 + Safari 14.1 target): single-file ES modules that were shipped unminified (Lenis, Embla, canvas-confetti, auto-animate, simplex-noise).
- **Bundled + minified with esbuild 0.28.2** into one self-contained ES module (no imports): OGL (`ogl.min.js`, `ogl-core.min.js`), Motion (`motion.min.js`, `motion-mini.min.js`), anime.js (`anime-lite.esm.min.js`), Swiper (`swiper.esm.min.js`, `swiper-lite.esm.min.js`), morphicons, NumberFlow. Each output starts with a banner naming the package, version, licence and build.
- **Renamed** `.mjs` builds to `.js` (some servers do not map `.mjs` to JavaScript; this site sends `X-Content-Type-Options: nosniff`).
- **Icon SVGs re-packaged** into `<symbol>` sprites with semantic ids (e.g. `#play`); attributes moved into a `style` so CSS custom properties can change stroke width, fill and duotone colour. Paths are unchanged.

## Not vendored (licence or compatibility)

- **GSAP** (not an OSI licence), **typed.js 3.x** (GPL-3.0), **Remix Icon >= 4.9** (custom licence), **useAnimations** Lottie icons (CC BY, redistribution in templates forbidden), LottieFiles marketplace animations (Lottie Simple License, not OSI).
- **React Bits** (reactbits.dev): "MIT + Commons Clause" since its first licence file (2025-02-08); it forbids redistributing the components "as a ported version". Used only as visual inspiration; no React Bits code is in this folder.
- **three.js**: a minimal shader setup is 130 KB gzip (over the 150 KB per-page effects budget together with any effect code); OGL covers the same effects at 19-25 KB.
- **tsParticles 4.x**: throws "OffscreenCanvas is required" on Safari/iOS < 16.4; 3.9.1 is vendored instead.
