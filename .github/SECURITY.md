# Security policy

## Supported versions

| Version | Supported with security fixes |
|---|---|
| 1.2.x | yes |
| 1.1.0 and older | no: please upgrade to the newest release ([UPGRADE.md](../UPGRADE.md)) |

Security fixes come as new releases. JavCMS 1.2.1 and newer install them with one click on
**Admins & backup › Updates** (signed releases from this repository only); a 1.2.0 site updates once with the
update bridge (see [UPGRADE.md](../UPGRADE.md)).

## Report a problem privately

Please **do not** open a public issue, discussion or pull request for a security problem, and do not post details
in any public chat.

Use one of these private channels:

1. **GitHub private vulnerability reporting:** open the [Security tab](https://github.com/avdbapi-com/javcms/security)
   of this repository and click **Report a vulnerability**
   ([direct link](https://github.com/avdbapi-com/javcms/security/advisories/new)). Only the maintainers can read it.
2. **Telegram:** send a private message to [@avdbapi_support](https://t.me/avdbapi_support).
   Write "security report" in the first line; we reply and agree with you how to send the details.

Please include:

- the JavCMS version, PHP version, database and web server,
- the affected file, page or admin feature,
- the steps to reproduce it, and what an attacker could do with it,
- a proof of concept if you have one (a test site, never a site you do not own),
- whether and how you want to be credited.

## What happens next

- We confirm that we received your report, usually within 3 working days.
- We check it, keep you updated and agree with you on a date before anything is made public.
- When a fix is ready, we publish a release and a GitHub security advisory, and we credit you if you wish.

## Scope

In scope: the JavCMS code in this repository (PHP, templates, JavaScript and the installer) and its default
configuration files, the one-click updater (JavCMS 1.2.1 and newer) and the files of each GitHub release: the zip,
the signed release manifests and the update bridge `javcms-update.php`.

Out of scope: problems of a server setup or hosting panel, third-party services (AVDB API, xvidapi, ad networks),
denial of service by sheer traffic volume, and reports from automated scanners without a working example.

For keeping your own site safe (file permissions, admin address, floods, Cloudflare), read
[docs/SECURITY.md](../docs/SECURITY.md).
