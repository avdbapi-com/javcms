# Contributing to JavCMS

Thanks for helping to make JavCMS better. Bug reports, ideas, docs fixes, translations and code are all welcome.

## Before you start

- Questions and setup help: the [help center](https://help.avdbapi.com), the [Telegram group](https://t.me/+jj6McO-iYfA0MTM1) and [@avdbapi_support](https://t.me/avdbapi_support) on Telegram.
  Issues are for bugs and feature requests.
- Search the [issues](https://github.com/avdbapi-com/javcms/issues) first: your topic may already be there.
- Security problems: never in public. Follow [SECURITY.md](SECURITY.md).
- Everyone who takes part follows our [Code of Conduct](CODE_OF_CONDUCT.md).

## Report a bug

Use the **Bug report** form. Give the JavCMS, PHP, database and web server versions, the steps to reproduce the
problem and what you expected. Remove passwords, keys and personal data from logs and screenshots, and keep
screenshots safe for work.

## Suggest a feature

Use the **Feature request** form: describe the problem first, then your idea. One idea per request.

## Change code, themes or docs

1. Open an issue first for anything bigger than a small fix, so we can agree on the approach.
2. Fork the repository and create a branch from the default branch, `main`.
3. Keep the change focused: one topic per pull request.
4. Follow the existing style of the file you change. JavCMS runs on **PHP 7.4 to 8.5** and on **MySQL 5.6+ /
   MariaDB 10.1+**: do not use syntax or SQL that older supported versions lack.
5. Text that visitors or admins see belongs in the language files (`application/lang/` for the admin and the
   language files of the themes), in English at least.
6. Update the docs (README, INSTALL, UPGRADE, CHANGELOG or `docs/`) when the change needs it.
7. Test on a test site with safe-for-work test data, never on a production site.
8. Open the pull request and fill in the template.

## Licenses and third-party code

- JavCMS 1.2.0 and later is under the **JavCMS Community License 1.0** ([LICENSE](../LICENSE)): free to use, change
  and share, never sold. By sending a contribution you agree that it is published under this license.
- Keep the short JavCMS notice at the top of every JavCMS file you change.
- Third-party code, fonts, icons and images keep their own licenses. Add only material whose license allows free
  redistribution, say where it comes from, and list it in [NOTICE](../NOTICE). Do not add code from paid or
  "nulled" scripts and themes.

## Content rules

JavCMS lives on GitHub, so everything in the repository (code, docs, examples, test data, issues and pull requests)
must be safe for work: neutral product language, no adult wording, no adult imagery, no real site data.
