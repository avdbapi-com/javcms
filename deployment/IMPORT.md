# Import videos into JAVCMS (AVDB and xvidapi)

Open **Import › Import videos** in the admin sidebar. One tab per video source (AVDB, xvidapi); the tabs, panels and limits are driven by each provider's capabilities, never by its name. The **Import from AVDB** button on **Advanced › Other import tools › Other API sources** opens the same tool. You do not need to write an API URL or add a collection source. Step-by-step guides for beginners: `docs/IMPORT.md` and `docs/HOSTING.md` of the release.

## Sources, scopes and the estimate (1.2.0)

**Providers.** `application/common/util/ImportProviders.php` registers `avdb` (AvdbProvider) and `xvid` (XvidClient), both implementing `ImportProvider`. Capabilities (stable cursor, ids per request, translation locales, category names by locale, allowed embed hosts and path, minimum request interval, provider parent category) are data: the admin page and the job engine branch on them. Every job, map row and schedule plan carries its provider; old jobs read as `avdb`.

**Scopes.** xvidapi (6.6 million videos): whole catalog (default and preselected, `stable_cursor` required; the xvidapi `xvidexport` snapshot when its manifest is valid, else keyset export pages, else id windows), newest 10,000 / 50,000 / 100,000 / 1,000,000, newest N, one category (the whole category). The same scopes exist for AVDB where its capabilities allow them ("Large import"). No size rule: the web page starts every scope; a web job continues by the open page, then by `AvdbImport::tickWeb()` after a visitor response has been sent (`fastcgi_finish_request` / `litespeed_finish_request` only, at most every 30 s, never inside a gate slot), the schedule callback and `tools/import.php --tick`. One confirmation checkbox (`confirm=1`) only for jobs above 1,000,000 videos or above half of the space left. The storage target (`StorageGuard::importTarget()`: 0.8 x the limit, or the free disk minus the closing search-step reserve and 2 GB) is the only stop: the job completes with `stop_reason=storage_target`. A job stops at 1.2 x its estimate.

**Estimate (`avdbimport/estimate`).** Videos, database size lean and full (xvid 1.93 KB / 2.43 KB per video incl. compressed search document and map row; AVDB 3.1 KB plus about 18 KB with all 16 translations), current size from `information_schema`, size after the import (x1.3 growth on secondary indexes), the database limit (`app.db_limit_gb`: GB, `auto`, `none`), peak disk (binary logs when `@@log_bin` is on: `ImportProviders::binlogKbPerVideo()` per video by `@@binlog_format` and the row-annotation setting, measured with real-sized rows: xvid 14.18 KB row format + annotations, 8.31 KB row format, 6.48 KB mixed or statement, unknown = row + annotations, C7 `binlog.kb_per_video` / `binlog.format`; an import longer than the expiry keeps only the last `expire_days`; search rebuild copy and sort; free space of `@@tmpdir` where the server is local), requests and provider time (one request per 5 s), import time here (8 ms per item in CLI bulk mode, the site's rolling rate after 10,000 items) and on shared hosting (x4), image size (12.7 KB per cover, opt-in), the language note, `fits` (informational), `stop_at_videos` (where the storage target stops the job; the page shows "Stops at about N videos when the database reaches X GB" and keeps the start button), `source` (`snapshot|keyset|windows`) and `download_mb`, `cli_recommended` (informational), the cron line and candidate PHP binaries, the slim-index offer above 1,000,000. The page shows the time with the tab open, with the tab closed (one 8 s step per 30 s while the site has visitors) and by cron.

**Command-line runner.** `tools/import.php` (`--list-providers`, `--estimate`, `--start`, `--run [--job=<id>] --max-minutes=4 [--budget=50]`, `--tick`, `--status`, `--pause --job=<id>` / `--pause --all`, `--upgrade`) refuses the web SAPI and PHP < 7.4, writes the heartbeat (`avdb_provider_state.cli_seen_at`), runs with `proc_nice(19)`, sleeps 200 ms between steps and resumes from the job lease (300 s) and checkpoints. Bulk scopes (above `BULK_MIN`, web or CLI) write items in transactions of 50 through the same validation path as every import, fetch the next provider window while the current one is applied, do not drain the search index during the job (the job ends with an append or a rebuild of the search table) and bump the list revision at most every 5 minutes. Before each step the runner waits while the load average is above 1.5 x CPUs or both deep request slots are busy.

**Provider pacing.** One shared slot per provider (`avdb_provider_state.next_request_at`) for the page, the schedule and the CLI: xvidapi one request per 5 s (windows of 500 ids or pages of 1,000 rows); slow answers double the interval (max 60 s); HTTP 429 Retry-After pauses the provider; 5xx, timeouts and cURL 6/7/28/35/52/56 back off 60 s x 2^n (max 1 hour) with the job still running; 8 failures in a row pause the job with a readable message; a Cloudflare challenge (HTML body, `cf-mitigated`, 403/503 from `server: cloudflare`) pauses the provider for 1 hour. User agent `JavCMS-Import/<version> (+<site host>)`. Storage errors (1114, 1021, 1142, errno 28) pause the job and the schedule at once without retry (`StorageGuard`). The database limit pauses imports at 0.9 x the limit, or below 2 GB / 10 % free disk.

**Placement.** When a scope job starts in a site that already holds 1,000 or more videos of another provider, new rows get `vod_time = job start - rank` (keeps the provider's own order on top, "as new arrivals"; setting `import.placement`), the real upload date goes to `vod_pubdate`. Otherwise new rows take the provider time when it is between 2005-01-01 and now + 1 day. Existing rows are never re-timed.

**Presence sweep and takedowns.** Weekly windows of 500 local map ids per provider (above 200,000 mapped ids: a rotating quarter per week). A missing id gets `missed_at`; a recheck at least 24 hours later hides it (`map.state=1`, `vod_status=0`, reversible) and purges its cached pages. Circuit breaker: a window answering 0 rows where the previous sweep saw at least 50 % present is a failure (retry later, nothing hidden); a run that would hide more than 2 % of the provider's videos or more than 5,000 aborts with an admin notice. Hidden videos older than 30 days are deleted only by the administrator.

**Tombstones, undo and remove all.** Admin deletes of imported videos keep the map row as a tombstone (`vod_id=0`, `state=2`); scheduled and bulk jobs skip tombstones; a manual selected import can override them. Undo and "remove all videos from <provider>" are resumable jobs (500 videos per step) that delete the videos and their map rows (no tombstones), update the search index, mark counts dirty and purge the cached pages of their id range once at the end. "Remove all" also disables that provider's schedule plan.

**Schedule.** One schedule row with one plan per provider; the scheduler starts only incremental (`recent(3|24|168 h)`) and presence jobs (or the export feeds), one at a time; schedules never start bulk scopes, but they step a running web job. The managed AVDB trigger drives both providers; sites with cron can use `tools/import.php --tick`.

**Rollback to 1.1.0.** `php tools/upgrade.php --prepare-rollback` (or `php tools/import.php --pause --all`) pauses every job and schedule plan of every provider first: 1.1.0 ignores the provider column and would send xvid ids to AVDB.

## AVDB: find, review and import

### Try your first import

1. Wait for **Connected** and the first preview page. Previewing does not change your library.
2. Search an exact movie code, a title, actor or studio. Select two or three videos. **Details** shows metadata and playback source names before you import.
3. Open **Review import**. Selections switch the scope to **Only the videos I selected**. With no selection, **All videos matching these filters** is the default. All 17 metadata languages are requested by default; only real available extra translations are saved.
4. Connect each AVDB category to one of your local video categories. The enabled `ngm3u8` player is preselected when available. The editable default player covers newly encountered sources; individual source choices take priority. Create or enable an appropriate local category/player in the normal admin settings if needed.
5. Choose **Save as drafts** if you want to check the results before publishing. Keep **Smart refresh and repair** for existing videos and a small item limit.
6. Read the summary, then click **Start this import**. Keep the page open while it runs. Review added, skipped and failed items in **3. Progress and history**, then check the videos in your normal video list.

## Find exactly what you need

Filters combine: a movie must satisfy all filled fields. Leave fields empty to include any value. **Reset filters** restores the initial preview without clearing your selected videos; **Clear selection** does that separately.

| Filter | Use |
| --- | --- |
| Search titles, names or tags | Full-text keywords. Use the separate actor/studio filters for a specific field. |
| Exact movie code | An exact provider code, such as the displayed movie code or slug. |
| Category | One AVDB category. This is distinct from the local destination category. |
| Actor / Director / Studio | Names in the corresponding provider fields. |
| Genre | Keywords in provider genre/class metadata. Tags use the keyword search. |
| Year / release date / range | A year, valid release date such as `2024-05-10`, range such as `2020-2026`, or comma-separated years/dates. Malformed source dates remain visible but cannot be chosen as valid filters. |
| Updated in the last hours | `24` for one day, `168` for one week. |
| Completion | Completed or ongoing; leave Any to include both. |
| Playback source | A provider source key shown in a video's Details. |
| Order | Recently updated first or oldest updates first. |
| Videos per page | 1,000 by default using the native API default; optionally choose 10, 20, 50 or 100. |

**Browse actors/directors/studios/genres/tags/years/playback sources** opens choices from the provider catalog, with Previous/Next pages. Type the beginning of a value to receive case-insensitive suggestions, or keep your own text. Category lists contain the provider's API-visible types. Suggestions come from a complete cached catalog index, show their update date, and may lag newly added records until that index is refreshed. The choices are independent of the currently previewed rows. If a lookup is unavailable, manual filtering still works. The separate **Tag** field filters provider tag text with literal partial-text matching. It combines with keyword, category, actor and other filters; it does not replace your keyword input or promise an exact tag token match.

Selections persist while you change preview pages and filters. Selected imports use those exact provider IDs. You can select up to 1,000 videos. Choose **All videos matching these filters** to import the matching category/filter without entering a last page. The default stop limit is 1,000; **Import every match (no item limit)** continues until matching results end. A specific first/last page range remains available. Find and Review share one editable filter form with the same suggestions. Click **Apply filters and preview** after changing Review filters. Matching-filter and page-range imports require a successful preview of the current filters; selected IDs keep their own snapshots. Page imports use the preview filters and page size. They stop at the page boundary, item limit, or exhausted results; an estimated search total is not a promise that every page exists. On a slow server, start with 10–50 items; large runs take longer and use more database and storage space.

Use **Quick start** for recent updates. **Save these filters** stores a named preset in this browser. It stores search filters, not passwords, import jobs, language choices or mappings.

## English or several languages

All 17 languages are requested by default. Choose **English only** to restrict metadata, or use **English plus available extra languages** to import any of Vietnamese, Thai, Indonesian, Korean, Chinese, Japanese, French, German, Spanish, Portuguese, Russian, Arabic, Turkish, Hindi, Malay or Filipino. **Select all languages** selects the extra languages; English is always included. Preview and new-import defaults use the native 1,000-row batch; saved jobs keep their original settings.

Every movie remains one local video. Translations are stored separately and matched by the provider video ID. Only available real translations are saved. Missing translations display English without creating additional language rows; empty translated fields fall back to English and then your original local value. Provider language catalogs can contain different subsets, so some movies will not have every requested translation.

Every bundled theme (all 18) has a language selector. When extra translations were imported, visitors who choose a language see video titles and texts in it; the choice is remembered in that browser. Cards, detail/play text, search, SEO and history use the selected text. A video's spoken-language field, local ID, slug, address and playback data keep their original meaning. All 18 bundled themes use the imported translations; a theme of your own can use the `vod_display_*` fields and the `AvdbLanguage` helper.

## Existing videos and category/player mappings

Previously imported videos are recognized by AVDB provider ID, even if their title has changed or is translated. **Smart refresh and repair** is the default for new imports: it refreshes changed provider text, metadata and playback, adds newly available real translations, and skips unchanged writes. Local identity, slug, publishing status, counters and local cover files are preserved unless you explicitly download replacement covers. Saved jobs retain their original policy. **Skip it** keeps the existing video. **Update only the fields I choose** lets you choose titles/descriptions, catalog metadata, covers, playback and translations independently. Existing slugs and fields outside those groups are kept. Locked and recycled videos are skipped.

A matching movie already created by another method is shown as **Link to #…** only when its exact movie code/slug has a unique local match. Check that link after reviewing both records. No record is linked just because its title resembles another title. Ambiguous local matches need manual review rather than an automatic update.

Fresh installations include all seven AVDB video categories immediately: Censored, Uncensored, Uncensored Leaked, Amateur, Chinese AV, Hentai and English subtitle. They are ready before the first import.

**Automatically create missing AVDB categories** is enabled by default. When you start an import, the tool matches unique enabled video category names and adds any missing API-visible AVDB categories. This also supports existing installations and future provider categories. Your selected destination mappings take priority, and existing categories are preserved. Disable the option to require your chosen local destinations. Category setup is recorded with the job; those categories remain after undo because other records may use them.

Each encountered category needs a valid local destination. Each playback source needs an enabled compatible player. Source names and their episode URLs stay aligned, including movies with several sources. A missing mapping produces a visible item failure rather than silently dropping a playable source. For a page range, preview representative pages to check their sources before starting.

## Fast imports and optional local covers

Selected, filtered and page-range imports use AVDB's native catalog endpoints. Newly started, unfiltered **All videos matching these filters** jobs with **Import every match (no item limit)**, 1,000 videos per page and descending order use the stable full-catalog export described below. **Download cover images to this site** is off by default: covers remain remote URLs and no image is fetched during import. Use **Quick item limit** to choose 100, 200, 500 or 1,000 items, or enter a custom limit. Your selected IDs or explicit page boundaries still control what is imported; choosing a large limit does not select more videos automatically.

English discovery uses native import batches; available translations are fetched together in bounded groups rather than one request per language. Database writes keep individual checkpoints and short request budgets, so pause, retry, identity matching and undo remain available. Real runtime depends on provider response time, your database and your chosen languages; local test timings are not a promise about network import speed.

Enable the cover option only when you want local copies of `vod_pic`, `vod_pic_thumb` and `vod_pic_slide` covers. Screenshots and other image fields keep their provider URLs. Downloads run one image per step and are validated before saving under `upload/vod/avdb/`. Only public HTTP/HTTPS destinations and valid JPEG, PNG, GIF or WebP images up to eight MiB are accepted; redirects, internal network destinations, SVG and HTML are rejected. A failed download is shown rather than silently treated as a local image. Existing locked/recycled/skipped videos are not downloaded. With Smart refresh, enabling downloads explicitly requests replacement covers, including local covers. For selected-field updates, also select the **Cover images** field group.

Provider image fields are optional metadata. Syntactically invalid, non-text or oversized image URL entries are discarded and reported as warnings, so an otherwise valid playable video can still be imported. Valid screenshot entries are retained in their original order; invalid or oversized entries are omitted. New videos may have blank image fields when no valid image is available. When an existing video's image field has no valid replacement, its current image value is preserved. This does not relax playback, mapping or download validation: invalid playback and missing required mappings still fail the item, and a requested cover download must still pass the image checks above. TLS, HTTP, destination-safety, file-collision and filesystem failures during an actual download keep their normal failure and retry behavior.

Downloaded covers use content hashes and can be shared by videos. Undo restores metadata and original URLs while retaining saved files; it does not delete a cover another video may use. Ensure the web process can write this upload directory and your filesystem supports same-directory hard links for atomic publication.

## Full-catalog export and existing-site upgrades

New full-catalog jobs use the provider's additive `/api.php/avdbexport/vod` endpoint. It captures the highest eligible video ID, reads descending video-ID batches of at most 1,000 and saves the cursor together with each discovered batch. Gaps in IDs and the legacy offset-page ceiling do not omit eligible records. A short nonempty batch continues; only an explicitly empty final batch ends discovery. Videos added above the captured upper ID are left for a later import. The upper bound is not a database snapshot: provider edits, publication or recycle changes during a run can change eligibility. Saved historical jobs retain their original discovery route.

Full-export jobs request stored extra translations through `/api.php/avdbtranslationbatch/index`, with at most 500 IDs and 16 extra languages per request. The same `stored-title-script-v1` quality policy applies: unavailable or rejected text never becomes a synthetic translation or an English copy. Responses are limited to eight MiB. An oversized translation response reduces the saved batch limit and retries the same pending IDs; a single oversized item pauses for review. Ordinary selected, filtered and page jobs keep the previous 100-ID translation endpoint. Both new provider routes retain native API authorization and dedicated rate-limit buckets; no quota increase is required. The provider must have these additive routes deployed before starting a new full-export job. They are provider components and are not included as consumer controllers in this JAVCMS archive.

Fresh v9 installations include nonunique indexes on `vod_sub` and `vod_remarks` for exact movie-code matching. Existing installations should add equivalent full-column indexes before a large import. Back up the database and inspect its current indexes first. Do not add duplicate indexes if an equivalent index already begins with the full column. With the default `javcms_` prefix, the two additions are:

```sql
ALTER TABLE `javcms_vod`
  ADD INDEX `idx_vod_sub` (`vod_sub`),
  ADD INDEX `idx_vod_remarks` (`vod_remarks`);
```

Replace `javcms_` with your configured database prefix. If only one index is missing, add only that index. These are nonunique indexes; they do not delete, rename or merge video rows. Schedule index creation for an appropriate maintenance window on an existing large catalog, since database locking and runtime depend on the server. Do not rerun the fresh-install SQL against an installed site. Upgrading application files must retain the site's database configuration and installation lock.

## Pause, return, retry and undo

Live progress shows processed, added, updated, skipped and failed counts, the current stage, and the latest check time. **Show item results** filters added/updated/failed/queued/undone items; **Previous results** and **Next results** let you inspect every item, including large runs. Each result shows its actual available extra language codes and count; selecting a language does not guarantee the provider has a usable translation. You can refresh results without changing the job.

**Pause** saves progress. Choose the job under **Recent imports** and click **Resume** to continue. **Stop import** cancels future work and keeps already committed items; undo remains available. Closing the page stops its automatic requests, and you can return to the saved job later. Multiple requests cannot run the same job step at once.

Provider rate limits pause a job and show a retry wait. Wait for the displayed interval, refresh/select the job if needed, then resume. Repeatedly clicking retry will not bypass the provider limit. Connection failures leave checkpoints available. **Retry failed items** repeats failed items using this job's original settings. To correct an invalid mapping or change filters/languages, start a new reviewed job with the corrected settings; use the existing-video policy to control committed items.

**Undo this import** removes unchanged videos created by this job and restores updated records from this job's beforeimages. It processes in small steps. Videos or translations edited later are left untouched and reported as conflicts. Review conflicts manually; undo does not force overwrite newer work. Each administrator sees their own job history.

## Troubleshooting

- **No matching videos:** reset one filter, check the movie code, or try fewer combined filters. Localized provider catalogs are subsets; discovery always starts from English.
- **Start button unavailable:** apply the current Review filters successfully for All matching or a page range, or choose selected videos. Check that the default player is enabled and that any required individual category/player mappings are valid. Automatic category setup and the default player can fill these choices for you.
- **Provider unavailable / timeout:** reconnect after checking outbound HTTPS and DNS. Progress from earlier steps is saved.
- **Certificate validation fails:** fix your server's trusted CA configuration. On older Windows PHP/cURL, configure a maintained CA bundle through PHP's `curl.cainfo`; recent Windows cURL can use the native trust store. Do not disable TLS verification.
- **Windows image DNS points at localhost:** the downloader can query native public DNS independently of development hosts entries. This fallback requires Windows PowerShell and PHP process functions. If it is unavailable, correct your DNS or PHP configuration; private destinations remain blocked.
- **Schema setup fails:** the first import needs permission to create the four importer/translation tables using your configured database prefix. Fresh installations already include them. Ask your database administrator to apply `application/install/sql/avdb-import.sql` with the actual prefix if the web account cannot create tables. Previewing does not install tables or write catalog data.
- **A colleague cannot see the tool:** give that administrator's role the **Import from AVDB** rights (for example "AVDB: start an import", "AVDB: import history" and the "AVDB auto import" rights) in **Admins & backup › Admin accounts**.
- **A video will not play:** check that its mapped local player accepts the provider's URL format, then inspect the saved source/episode URLs. Importing does not certify an external video host's availability.

Provider field definitions and rate limits: [AVDBAPI documentation](https://avdbapi.com/index.php/label/help.html). For general installation/server setup see [deployment README](README.md). This distribution contains no installed database credentials or installation lock; an installed site should retain its lock.

## Permanently delete selected videos

The video list and recycle bin have a **Permanently delete** row action and selected-video button. **Move to recycle bin** keeps data and images for restore. Permanent deletion removes the selected videos, translations, comments, viewing records, role/topic/search links and related AVDB import mappings, snapshots and image checkpoints.

Exclusive local uploaded images and generated static detail pages are removed after the database transaction commits. Files referenced by another active or recycled video, catalog record or retained import snapshot are preserved. Remote image URLs are never fetched or deleted during cleanup. If file cleanup fails, the admin reports that the records were deleted but some files remain.

Pause active import discovery before deleting. Inactive unfinished matching/page discovery is stopped to prevent deleted videos reappearing. Old import history cannot restore or retry deleted items; start a new import to intentionally add those videos again. Permanent deletion cannot be undone from the recycle bin; retain your normal site backup when you need recovery.


## Clean all videos

The Video list and recycle bin provide **Clean all videos**. It covers every active and recycled video across all pages, regardless of the current filters. Open it to review the totals, then type **DELETE ALL VIDEOS** exactly to enable permanent cleanup. Administrator delete permission and CSRF protection are required. Opening or cancelling the review does not delete data.

Cleanup removes the confirmed video catalog, stored translations, video comments/viewing records/roles, old import mappings and item snapshots, and references in topics, articles and cached video searches. Categories, players, administrators, settings, other content modules and global records remain. **Uploaded images and generated static files remain on disk.** This action cannot be undone through the recycle bin or import history; use an existing database backup when rollback is required.

Work commits in bounded batches and saves progress. Keep the dialog open to continue, or reopen it to resume. Pause retains the cleanup gate; cooperating media edits and imports remain blocked until completion or cancellation. Cancel stops future batches and keeps already committed deletions. Closing the dialog sends no further automatic steps; a request already in flight can finish. The job captures an upper video ID, so videos with newer IDs are preserved. This is a fixed boundary, not a database snapshot.

The first confirmed start creates only the dedicated cleanup job table, using the configured prefix. It does not rebuild existing catalog/import indexes. If the database account lacks CREATE permission, apply application/install/sql/vod-cleanup.sql with the actual prefix before starting. Preview performs no schema installation. Historical imports affected by cleanup cannot retry, resume or undo removed data; start a new import to add videos again intentionally.


## Automatic AVDB imports

Automatic imports are OFF after installation. In **Import › Import videos**, tab AVDB, preview your filters, choose **All videos matching these filters**, review the language, category, player, image, update and publishing settings, then tick **Enable automatic imports**, choose **Repeat every** (15 minutes, 1 hour, 6 hours, 12 hours or Day) and click **Save reviewed settings**. The saved configuration is independent of later unsaved form edits. Imports repeat without needing visitors or an open browser, using the managed AVDB service and your public HTTPS site address. No Cron setup or customer-side worker installation is required.

**Pause automatic imports** preserves the current job (**Resume automatic imports** continues it). Turning automatic imports off prevents future work and unregisters the managed trigger; already imported videos remain. A running short database commit can finish before Off takes effect. A manual import and an automatic import cannot overlap. Failed or manually stopped jobs require administrator review; the scheduler does not silently retry them. Smart imports fill available missing metadata and update provider changes without creating duplicate identities.

The managed service must be reachable and your configured site address and installation directory must resolve to this installation over public HTTPS. Service outages use bounded retries and are visible in the controls. The first authorized Save creates only application/install/sql/avdb-schedule.sql using the configured prefix; GET status does not install schema. Hosts require the same PHP cURL, TLS and database capabilities as the manual AVDB importer. Tested on PHP 7.4 and PHP 8.5.
