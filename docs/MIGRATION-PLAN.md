# Jira Cloud to Redmine: test migrations and script upgrade

Start a Claude Code session on this repository, branch `redmine70-migration`, with:

> Read CLAUDE.md and docs/MIGRATION-PLAN.md, then carry on with the first open item of the work
> list on branch redmine70-migration. Report to me in Dutch at the end.

With several sessions in parallel, the session Jan starts is the coordinator; see "Sessions".

This file is the plan and the memory of that work. Update it as you go: results, findings, what
is left. Written 2026-10-13 from a reading of the code and Jan's answers in the planning session.

## Goal

- Run **test migrations** from Jira Cloud to two full Redmine instances, both on PostgreSQL 16:
  - **Redmine 5.1** with the GEOxyz plugins as they run in production today;
  - **Redmine 7.0** (`7.0-stable-GEOxyz`) with the plugins' `redmine70-migration` branches.
- Use those runs to find and fix bugs, raise code quality and documentation, settle the ideal
  workflow, and automate manual steps where that is worth it.
- **Out of scope:** the production migration itself. Jan does that later, by hand or partly with
  AI, using what this work produces.
- Test data may be used freely for the test migrations; staging and target data are deleted after.

## Status

| | |
|---|---|
| Branch | `redmine70-migration` (from `main` at `1de1fc9`) |
| Phase | 2 and 3 done (session B, 2026-10-13); phase 4 waits for the test project keys |
| Scripts | 16 scripts, ~39,000 lines of PHP, no shared library, no tests |
| Targets | Redmine 5.1 and 7.0; the scripts stay dual-target (unlike the plugins, 5.1 is not dropped) |
| Extended API | to be made compatible with Redmine 5.1, 6.1 and 7.0 (own repo, own branch) |

## Decided by Jan (2026-10-13)

1. **Jira access is read-only, enforced by Jira.** A scoped API token with read scopes only
   (`read:jira-work`, `read:jira-user`; a granular `read:*` scope is added only when a script
   proves it needs one; never a `write:*`, `delete:*` or `manage:*` scope). Scoped tokens only
   work through the gateway `https://api.atlassian.com/ex/jira/{cloudId}`. Before the first run,
   prove it is read-only with a write probe on an issue that does not exist: the answer must be a
   scope refusal (401), not a 404.
2. **Fix versions and components are not used at GEOxyz.** They stay out of scope: extracted into
   staging as today, not migrated.
3. **Large attachments: no code change.** The SharePoint offload stays in the code, switched off
   with `attachments.sharepoint.offload_threshold_bytes = null` (the default). Test for real how
   Redmine copes with very large attachments (videos of 1 to 2 GB), with a few files only, not
   every attachment.
4. **Mentions become `@login`.** That is Redmine core's mention syntax since 5.0: rendered as a
   mention, notifies the user on creation, and found by Jan's mention search. `redmine_zenedit`
   uses the same `@login` syntax and switches its own mention code off on Redmine 5.0+
   (`unless defined?(Redmine::Acts::Mentionable)`), so the choice is the same with or without
   zenedit. Today the scripts write `user#<id>` (a plain user link) from profile links, and the
   ADF path turns a mention into inline code text.
5. **Retry on rate limits** everywhere, central, for Jira and for Redmine.
6. **Extended API compatible with Redmine 5.1, 6.1 and 7.0.** This revises the 2026-10-07
   decision ("Redmine 7 only") for `redmine_extended_api` only. The webhook patch is applied only
   when `::Webhook` exists.
7. **Text conversion** (Jira to Redmine Markdown) must be tested thoroughly; it has caused trouble
   before.

## Measured on 2026-10-13 (reading the code, nothing run yet)

| # | finding | where |
|---|---|---|
| M1 | 8 helper functions copied in all 16 scripts (CLI, config, DB, timestamps); Jira/Redmine clients 13 to 15 times | `^function` across `[0-9]*.php` |
| M2 | No tests of any kind | repo |
| M3 | 429 handling in 4 of 16 scripts (09, 11, 14, 16); in `10_migrate_issues.php` one 429 during the issue search fails the whole project | `10_migrate_issues.php:588` |
| M4 | Gateway URL breaks the Jira client: `base_uri` plus a path with a leading slash (`/rest/api/3/...`) drops `/ex/jira/{cloudId}` | `createJiraClient`, every Jira call |
| M5 | Attachment downloads use the absolute site URL from Jira's `content` field; a scoped token needs the gateway | `09_migrate_attachments.php:441` |
| M6 | Two conversion paths: Jira's rendered HTML via `league/html-to-markdown` (main), ADF via the vendored `Karvaka/AdfToGfm` (fallback), plus ~1000 lines of regex post-processing | `lib/description_conversion.php`, `10_migrate_issues.php:1250` |
| M7 | Mentions: profile links become `user#<id>`; ADF mentions become inline code | `lib/description_conversion.php:557`, `Karvaka/AdfToGfm/src/Nodes/Mention.php` |
| M8 | Manual steps before/after a migration live in CHANGELOG.md: attachments prune off, locked users temporarily activated, LDAP sync off, workflows opened and reset, stealth mode, Apache `RACK_QUERY_PARSER_PARAMS_LIMIT` | `CHANGELOG.md` |
| M9 | Attachments are uploaded for tokens before issues exist; Redmine's prune task deletes unattached uploads older than a day, hence "prune off" in M8 | `09_migrate_attachments.php` push phase |
| M10 | Staging SQL is MySQL-only (`ON DUPLICATE KEY UPDATE`, `VALUES()`); GEOxyz runs no MySQL any more | all scripts |
| M11 | GEOxyz-specific relation types in `config.default.php` | `migration.relations.custom_mappings` |
| M12 | Worklogs (time entries) are not migrated; workflows are export-only | repo |
| M13 | Extended API `main` (what 5.1 runs) still has bugs fixed on its `redmine70-migration`: `notify=false` switches mail off process-wide (`Mailer.with_deliveries(false)`), so other users' mail is lost during an import; unstorable override values are saved as NULL instead of a 422 | `redmine_extended_api` |
| M14 | Large files: Redmine streams uploads to disk in 8 KB blocks and has no hard size cap (`attachment_max_size`, default 5 MB); core has no video player, a video is a download; Apache 2.4.53+ has `LimitRequestBody` 1 GiB by default | Redmine 5.1/7.0 `AttachmentsController`, `Attachment` |
| M15 | Good, keep: ETL with staging, mapping tables, idempotent reruns, `--dry-run`/`--confirm-push`, `automation_hash` preserves manual edits, Jira keys rewritten to `#id`, keyset paging on `/rest/api/3/search/jql` | |

## Work list

In this order. Each item records its result under "Results" when done.

**Phase 0, prerequisites (Jan)**

1. Create the scoped token (id.atlassian.com, Security, API tokens, "Create API token with
   scopes", app Jira, read scopes only) and put it in the cloud environment's settings as
   `JIRA_SITE` (e.g. `geoxyz.atlassian.net`), `JIRA_EMAIL`, `JIRA_API_TOKEN`. Never in the chat or
   the repo. A new session picks it up.
   **DONE** 2026-10-13 (Jan set the variables in the environment; prove read-only per decision 1).
2. Test projects. Jan (2026-10-13): **Survey Support Desk** has the most tickets. The coordinator
   sizes every Jira project first (issues, comments, attachments and their total size) and puts the
   choice to Jan: 2 or 3 small, varied projects for the baseline (rich formatting, large video,
   many custom fields), and Survey Support Desk as the volume run once the baseline is clean.
   **Chosen by Jan (2026-10-13, through the coordinator):**
   - baseline: **GEOS** (97 issues, 547 comments, 320 attachments, 1.0 GB, 29 custom fields),
     **SIS** (265, 477, 199, 0.2 GB, 18 custom fields, a 67 MB video), **OAC** (64, 77, 57, 0.25 GB,
     61 links, 22 subtasks);
   - conversion bench: **WIKI** added (1,457 issues; the only project with panels and most code blocks);
   - volume run: **SURV** (1,494 issues, 7,344 comments, 6,317 attachments, 98.7 GB) with an
     attachment sample: every attachment up to 512 KB, plus per file extension the largest file up to
     20 MB (about 4,305 files, about 1 GB); the rest stays in staging as metadata, is not downloaded or
     pushed, is reported as "skipped by sample" and counted as expected-absent by the verify tool. It is
     a test option, off by default, a fixed rule;
   - large attachments: LABO-562 attachment 33685 (mp4, 431 MB, the largest video in Jira) and
     SURV-267 attachment 11838 (tif, 1,811 MB). No video in Jira is larger than 431 MB.
   Jira as a whole: 15 projects, 4,911 issues, 13,677 comments, 10,095 attachments, about 116 GB.
3. 5.1 plugin set. Jan (2026-10-13): production runs each plugin's default branch, **except**:

   | plugin | branch on 5.1 |
   |---|---|
   | `redmine_custom_workflows` | tag `v2.1.2` (decided by Jan 2026-10-13; `master` and `5.x` are v3.1.1 and need Redmine 6, F2) |
   | `bless-this-redmine-sso` | `feature_version_2.0.0` |
   | `redmine_issue_templates` | `master-geoxyz` |
   | `redmine_subtask` | `develop` |
   | `redmine_ai_summary` | `main-GEOxyz` |
   | `redmine_mermaid_macro` | `mermaid10` |
   | `redmine_wiki_extensions` | `Redmine-5.1` |
   | `redmine_user_specific_theme` | `compatibility-with-redmine-5` |
   | `redmine_issue_view_columns` | `2.0.0` |

   Which plugins make up the set: the Redmine plugins in Jan's GitHub account (public and
   private). Show Jan the derived list once before building the 5.1 instance; on 7.0 every
   plugin uses its `redmine70-migration` branch.

**Phase 1, Extended API on 5.1, 6.1 and 7.0** (repo `redmine_extended_api`, branch
`redmine70-migration`, under that repo's CLAUDE.md and plan)

4. Guard the webhook patch with `defined?(::Webhook)`, lower `requires_redmine` to 5.1, restore
   the test paths removed in `d6013b3`, check Ruby syntax against the oldest supported Ruby.
   Tests on 5.1, 6.1 and 7.0 with PostgreSQL; e2e on each. Record the decision in that plan.

**Phase 2, test environments**

5. Redmine 5.1 with the production plugin set; Redmine 7.0-stable-GEOxyz with the
   `redmine70-migration` branches; PostgreSQL 16 for both; staging database as today (MySQL) until
   the baseline is in. REST API on, text formatting CommonMark on both. After setup, take a
   `pg_dump` of each and restore it before every run.

**Phase 3, minimal change to run at all**

6. Jira gateway support (M4, M5): one place that builds Jira URLs, relative paths, attachment
   downloads through the gateway. Read-only probe (decision 1) recorded with its answer.
   **DONE** 2026-10-13 (session B). Probe: `PUT /rest/api/3/issue/ZZPROBE-999999` and
   `POST .../comment` through the gateway both answer `401 {"message":"Unauthorized; scope does not
   match"}`; `GET /rest/api/3/myself` answers 200. Fix: `lib/jira_gateway.php`, one Guzzle
   middleware on every Jira client, on when `jira.cloud_id` is set; puts `/ex/jira/{cloudId}` back on
   leading-slash paths and moves absolute site URLs to the gateway. Proof: `01 --phases=jira`
   extracts 15 projects through the gateway; an attachment download returns 200 with the exact
   size (Jira redirects to `api.media.atlassian.com`). Tests: `tests/JiraGatewayTest.php`, 3 of 8
   fail without the rewrite.

**Phase 4, baseline**

7. Run every script, otherwise unchanged, on the test projects, to 5.1 and to 7.0. Record every
   failure, warning and manual intervention in "Findings". Fix nothing yet.
   **DONE** 2026-10-13 (session B): two runs per target, see Findings B1 to B23 and the verify table
   there. `tools/testenv/run_all.sh 51|70 LABEL` repeats a full run from a clean Redmine and staging.

**Phase 5, measuring tools**

8. Verify tool: compare Jira and Redmine after a run. Counts per project, tracker and status;
   for a sample of issues the description, journals, attachments (name, size, checksum),
   watchers, relations and custom field values. Output a pass/fail report. From here on "green"
   means this report.
   **DONE** 2026-10-13: `tools/verify.php --projects=GEOS,SIS,OAC [--sample=25]`, rules in
   `lib/verify.php` (21 PHPUnit tests). Live Jira against live Redmine; staging only maps ids.
   Renders `user#id`/`@login` as names and ignores Jira browse URLs (rewritten to `#id` by design),
   as Redmine shows them. A run of 3 projects takes about 2 minutes.
9. Text conversion bench (decision 7), see "Text conversion protocol".
   **Steps 1, 2 and 4 DONE** 2026-10-13: `tools/conversion_bench.php`, results under that protocol.
   Step 3 (visual check with Jan) and 5 (PHPUnit cases) follow in phase 7.
10. Large attachment test (decision 3), see "Large attachment protocol".
    **DONE** 2026-10-13, results under that protocol.

**Phase 6, code quality without behaviour change**

11. Shared library under `src/` (PSR-4): config, Jira client and Redmine client with central
    retry (decision 5), database, CLI, mapping helpers. Scripts keep their CLI. PHPUnit for the
    pure transformations, PHPStan, PSR-12. Proof of no behaviour change: the push payloads of a
    dry run are identical before and after, on the same staging data.

**Phase 7, fixes**

12. Mentions to `@login` (decision 4), in both conversion paths.
13. Conversion fixes from the bench, each with a PHPUnit case built from an anonymised
    reproduction of the Jira input.
14. Every finding from the baseline: fixed, or a line saying why not.

**Phase 8, workflow and documentation**

15. Runbook for a full migration: order, commands, what to check after each step, where the
    decisions live. Replace the manual steps of M8 where it is worth it, each as a choice for Jan
    with options (examples: upload and attach per issue so prune can stay on; an Extended API
    import mode that skips workflow and initial-status checks and accepts locked users, admin
    only, `/extended_api` only).
16. README rewritten from the runbook; CHANGELOG back to a changelog.

**Phase 9, final runs**

17. Full test run on both targets with the final code; verify report green; numbers in "Results".

## Sessions

The work runs in parallel sessions that write **disjoint repositories**, so they never conflict.
Exactly one session writes this file: session B.

**Coordinator** (the session Jan starts; it has the Jira variables). It does not build. It:

1. Checks that `JIRA_SITE`, `JIRA_EMAIL` and `JIRA_API_TOKEN` exist (names only, never print a
   value), looks up the cloudId (`https://$JIRA_SITE/_edge/tenant_info`) and proves the token
   read-only with the write probe of decision 1. A token that is not refused: stop and tell Jan.
2. Sizes every Jira project (issues, comments, attachments, total attachment size) through the
   gateway, and puts the test project choice to Jan (work list 2).
3. Starts sessions A and B with `create_session`: same environment (so they inherit the Jira
   variables), the prompts below with its own session id filled in, tag `jira-migration`.
4. Follows them (`get_session`, `list_events`). Puts every question a session sends to Jan as a
   clickable multiple choice: the options, a plain explanation of each, and a recommended option.
   Sends Jan's answers back with `send_message`. Sends B the chosen test project keys.
5. Tells Jan, in Dutch and briefly, when a session finishes a phase, is blocked, or fails; never
   does a session's work itself.

| session | repository, branch | writes | starts |
|---|---|---|---|
| A | `jcatrysse/redmine_extended_api`, `redmine70-migration` | that repository only | at once |
| B | `jcatrysse/jira2redmine_migration`, `redmine70-migration` | this repository only | at once; phase 4 waits for the test project keys |

Prompt A:

> Read CLAUDE.md and docs/REDMINE7-MIGRATION.md. New decision by Jan (2026-10-13), revising the
> 2026-10-07 "Redmine 7 only" for this plugin: make it compatible with Redmine 5.1, 6.1 and 7.0.
> Apply the webhook patch only when ::Webhook is defined, lower requires_redmine to 5.1, restore
> the 5.1 test paths removed in d6013b3, keep the Ruby syntax valid on the oldest supported Ruby.
> Tests and e2e on 5.1-stable, 6.1-stable and 7.0-stable-GEOxyz with PostgreSQL. Record the
> decision and the results in docs/REDMINE7-MIGRATION.md. Context: jcatrysse/jira2redmine_migration,
> branch redmine70-migration, docs/MIGRATION-PLAN.md, work list 4. Questions for Jan go to the
> coordinator session <id> with send_message; meanwhile pick the option most likely right, log it
> as open, and carry on. Report in Dutch at the end, also to the coordinator.

Prompt B:

> Read CLAUDE.md and docs/MIGRATION-PLAN.md, then carry out the work list from phase 2 on
> (phase 0 and 1 belong to the coordinator and session A). For the 5.1 baseline use
> redmine_extended_api `main` (what production runs); rerun on its redmine70-migration branch
> once session A is done. Questions for Jan go to the coordinator session <id> with send_message;
> meanwhile pick the option most likely right, log it as open, and carry on. Report in Dutch at
> the end of each phase, also to the coordinator.

## Text conversion protocol

1. Curated set: real issues chosen per construct (tables, code blocks, panels, nested lists,
   inline images, attachments links, mentions, issue links, task lists, headings, quotes, colour,
   emoji, dates, expand), from the test projects. List the issue keys used here.
2. Automatic check over all migrated issues and comments: the plain text of Jira's rendered HTML
   against the plain text of Redmine's own CommonMark rendering of the converted Markdown. Report
   issues that lose words, links or images. The result is a number: x of N without loss.
3. Visual check for the curated set: Jira and Redmine rendering side by side, shown to Jan (not
   committed, see Rules).
4. Both paths (HTML and ADF) are measured; the plan records which one is better per construct.
5. Every defect found becomes a PHPUnit case with an anonymised input and the expected Markdown.

**Results 2026-10-13 (session B).** Every staged description and comment, rendered by Redmine 5.1's
own CommonMark formatter, against Jira's rendered HTML. "Without loss": at least 99% of Jira's words,
no fewer links or images, the element of each construct present (table, pre, heading, blockquote,
nested list, mailto, img), and no visible Markdown syntax (`**`, `\|`, `](`, `|---`, HTML tags).

| set | path | items | without loss |
|---|---|---:|---:|
| GEOS, SIS, OAC, WIKI (bench staging) | html (main) | 4,648 | 4,630 (99.6%) |
| | adf (fallback) | 4,648 | 2,098 (45.1%) |
| GEOS, SIS, OAC (run 2 staging) | html | 1,444 | 1,440 (99.7%) |
| | adf | 1,444 | 747 (51.7%) |
| | pipeline (what is pushed) | 1,139 | 1,057 (92.8%) |

- **Which path:** the HTML path is better for every construct; ADF loses links (2,313 items), code
  blocks (31 of 33), images and mentions. Keep HTML as the main path; ADF only where Jira gives no HTML.
- **The losses are in the pipeline after the converter** (phase 7): inline images in comments become
  plain attachment links (46 of 54 items with attachment links), mentions glued to the next word
  ("user#12thanks"), `mailto:` domains lost (B15), Markdown links that do not render (`](` visible, 11),
  a heading and quote lost in one comment, `**` visible inside raw HTML blocks.
- HTML path defects (18 of 4,648): WIKI tables written in MediaWiki syntax and `<nowiki>`/`<pre>` text
  that Jira itself shows as text (WIKI-280, -282, -294, -295, -843), raw HTML left in 6 items.
- Constructs absent from these projects: task lists, dates, expand. Present (issue counts): mention
  664, image 311, issue link 205, attachment link 111, nested list 61, panel 39, code block 30,
  table 28, email link 24, emoji 23, heading 16, status lozenge 14, colour 11, quote 10.
- Curated set (step 1), two or three per construct: table GEOS-10, OAC-39, SIS-12; code block SIS-249,
  WIKI-66, WIKI-843; panel WIKI-161, WIKI-185; nested list GEOS-15, OAC-19; image GEOS-87, OAC-47;
  attachment link GEOS-8, GEOS-23; mention GEOS-10, SIS-162; issue link GEOS-65, OAC-16; heading
  GEOS-102, WIKI-254; quote GEOS-10, OAC-17; colour WIKI-185, WIKI-406; emoji GEOS-22, SIS-151; status
  lozenge SIS-85, WIKI-13; email link GEOS-41, SIS-211; MediaWiki text WIKI-280, WIKI-294.

## Large attachment protocol

On 5.1 and 7.0, with `attachment_max_size` raised (e.g. 4 GB) and SharePoint off:

- files: one or two real large videos from the test projects; if none is large enough, one
  synthetic 2 GB file;
- measure: upload time through the API; memory of the Redmine process during the upload; memory
  of the PHP script; issue page load time with the attachment (browser); download time; whether a
  range request is honoured (206 or 200, i.e. whether a video can be seeked), with and without
  X-Sendfile where the test server allows it;
- list what only production can show: Apache `LimitRequestBody` (1 GiB default since 2.4.53),
  proxy timeouts, X-Sendfile, backup size.

**Results 2026-10-13 (session B)**, `tools/testenv/large_attachment_test.sh`, Puma directly (no
Apache), `attachment_max_size` 4 GB, the same Guzzle streaming upload as 09:

| | 5.1 video 431 MB | 5.1 tif 1,811 MB | 7.0 video 431 MB | 7.0 tif 1,811 MB |
|---|---|---|---|---|
| upload through the API | 4.7 s | 17.2 s | 4.8 s | 23.0 s |
| PHP peak memory | 4 MB | 4 MB | 4 MB | 4 MB |
| Redmine RSS during upload | 270 to 378 MB | +47 MB | 435 to 577 MB | 460 to 670 MB |
| stored size, sha256 of the download | equal, equal | equal, equal | equal, equal | equal, equal |
| download | 1.6 s | 4.9 to 15.7 s | 0.6 s | 0.9 to 2.8 s |
| **Redmine RSS during download** | grows | **275 to 2,087 MB, stays** | flat | **284 to 285 MB** |
| range request `bytes=1000-1999` | 200, whole file | 200, whole file | 200, whole file | 200, whole file |
| issue page load (Chromium) | 0.7 s | 0.4 s | 0.6 s | 0.4 s |

- Upload works on both up to 1.8 GB; the scripts stream (4 MB PHP) and Redmine writes to disk.
- **5.1 reads a download into memory** and keeps it (Puma, Rails 6.1): a 1.8 GB file costs 1.8 GB
  RSS per worker. 7.0 streams. In production Apache with X-Sendfile would serve the file itself:
  check whether X-Sendfile is on for 5.1 before large files go in.
- **No range requests** without a web server in front: a video cannot be seeked in the browser; with
  Apache and X-Sendfile Apache answers ranges (206).
- Only production can show: `LimitRequestBody` (1 GiB default since Apache 2.4.53, so the 1.8 GB
  upload fails behind a default Apache), proxy and Passenger timeouts for a 20 to 30 s upload,
  X-Sendfile, backup size.
- **Jan 2026-10-13:** production 5.1 runs Apache with defaults: no X-Sendfile, `LimitRequestBody`
  1 GiB, so a migration onto 5.1 cannot upload the 1.8 GB file (and 5.1 holds every download in
  memory, see above). The new server is configured optimally: Apache in front of the 7.0 test instance
  with X-Sendfile and a `LimitRequestBody` sized for the largest attachment is measured next, and the
  recommended configuration goes into the runbook. To do.

## Rules

- Run things yourself and quote the numbers. Never call something green you did not see green.
- Jira is read-only: scoped read token, proven at the start of every session that uses it.
- **This repository is public. No Jira data in it**: no payloads, staging dumps, attachments,
  real issue text in fixtures, or screenshots of real issues. Test fixtures are anonymised
  reproductions. Evidence with real data stays in the session and is shown to Jan, not committed.
- Secrets only in the environment or `config/config.local.php` (gitignored); never in logs or
  output.
- Behaviour-preserving refactoring first, behaviour changes in separate commits; every fix gets a
  test that fails without it.
- The scripts stay dual-target (Redmine 5.1 and 7.0); a difference between the two is detected,
  not configured by hand.
- Commits as Jan Catrysse, one subject line saying what and why; never push to `main`.

## Open questions for Jan

- Test projects (work list 2).
- PHP version on the machine where the scripts will run in production.
- Staging database: stay on MySQL or move to PostgreSQL (decide after the baseline).
- Worklogs to time entries: in scope or not.
- Which manual steps to automate (decide after the baseline, per step).
- Attachment names (B14), Jan 2026-10-13: test how 5.1 and 7.0 handle two attachments with the same
  name on one issue and in one journal (list, downloads by checksum, inline image in description and
  comment, API); original names if Redmine handles it correctly, otherwise keep `{jiraId}__`. To do.
- Granular read scopes (approved by Jan 2026-10-13: read:group:jira, read:user:jira,
  read:project-role:jira, read:workflow:jira, read:workflow-scheme:jira, read:issue-type-scheme:jira,
  read:issue-type-screen-scheme:jira, read:screen:jira, read:screen-scheme:jira, read:screen-tab:jira,
  read:screenable-field:jira, read:field-configuration:jira, read:field-configuration-scheme:jira,
  read:avatar:jira): waiting for the new token; then write probe again and rerun 03, 04, 16.

## Findings

Before the baseline (session B, 2026-10-13):

| # | finding | effect | action |
|---|---|---|---|
| F1 | `5.1-stable-GEOxyz` sets `session_store :active_record_store` but its Gemfile lacks `activerecord-session_store` (7.0 has it) | 5.1 does not boot from the repo alone | Jan: Gemfile.local through the test setup, nothing committed to the Redmine fork (done in `setup.sh`) |
| F2 | `redmine_custom_workflows`: Jan says production 5.1 runs `main`; the repo has no `main`, and `master` (2aacc23) and `5.x` (38d6a53) are v3.1.1 with `requires_redmine 6.0.0`: "PluginRequirementError ... requires Redmine 6.0.0 or higher but current is 5.1.13.stable" | does not load on 5.1 | **decided**: tag `v2.1.2`. Custom workflow scripts run on issue save through the REST API, so they are part of an import; the test instances have none of production's scripts |
| F3 | `redmineup` >= 1.1.5 defines route `auto_complete_taggable_tags`, `redmine_contacts` master too | 5.1 does not boot with the newest gem | test env pins `redmineup` 1.1.4 in the lockfile |
| F4 | `issue_recurring` loads models from its rake file | `rake db:migrate` fails on an empty database | setup moves it aside for the first migrate |
| F5 | Jira client with a scoped token on the site URL (no gateway) | `01 --phases=jira` reports "0 project records processed" and exits 0, no error | phase 7: an empty or unauthorised extraction must fail loudly |
| F6 | Redmine calls use leading-slash paths too (`/roles.json`, `/issues/%d.json`, ...) | a Redmine under a sub-path (`https://host/redmine/`) would lose the sub-path, same bug as M4 | phase 6 (shared Redmine client) |
| F7 | Through the gateway Jira returns attachment `content` URLs on the gateway already | M5 only matters for staging rows extracted through the site URL; the rewrite covers both | none |

**Baseline (phase 4), session B, 2026-10-13.** GEOS, SIS, OAC (426 issues, 1,101 comments, 576
attachments) to Redmine 5.1 (Extended API `main` @ 132fff5) and 7.0, scripts unchanged apart from the
gateway. Run 1 followed the scripts and their defaults; run 2 applied today's documented manual steps
up front (`tools/testenv/manual_steps.rb`: open workflows, activate users and add them to the projects
as a stand-in for 04, which the token cannot run yet; `view_watched_issues` for watchers) and the
documented config option `default_redmine_author_id`. Logs, staging and reports stay in the session
(real data). **5.1 and 7.0 give the same numbers, except B19.**

Verify, 25 sampled issues per project (75; 62 exist in Redmine):

| check | run 1 | run 2 | cause in run 2 |
|---|---|---|---|
| presence | 62/75 | 62/75 | B7, B8 (issues not created) |
| counts total / per tracker+status | 0/3, 0/37 | 0/3, 18/29 | issues not created |
| status | 1/62 | 62/62 | run 1: B12 |
| watchers | 2/62 | 62/62 | run 1: B20 |
| subject, tracker, author, assignee, created | 62/62 | 62/62 | |
| parent | 60/62 | 58/62 | B10, B22 |
| priority | 61/62 | 60/62 | B13 (derived from subtasks) |
| description words / links / images | 58, 60, 61 of 62 | 58, 59, 60 of 62 | B10, B15, B22 |
| comments (journal holds >= 95% of the words) | 142/145 | 142/145 | B15 |
| attachments name+size / sha256 | 62/62, 95/95 | 62/62, 95/95 | |
| attachments original filename | 37/62 | 37/62 | B14 |
| relations | 27/32 | 27/32 | B18 |
| custom fields (value present) | 66/97 | 66/97 | B7 |

Run 2 totals per target: issues 365 created + 6 waiting for a parent + 2 update failed, 48 failed,
5 manual; journals 939 pushed (749 belong to issues not created); attachments 367 attached, 208
uploaded but never attached (issues not created; Redmine's prune removes them); relations 60 created,
60 manual; watchers 777. Verify on both targets: **FAIL, 106 failures**.

| # | finding | effect | where |
|---|---|---|---|
| B1 | Tracker name longer than 30 characters ("Automated Feedback from Jira tickets") | 422 on create; custom field "Department" then MANUAL (missing tracker) | 07 transform |
| B2 | Scope gaps: `group/member`, `role`, `workflow*`, `issuetypescheme*`, `screens`, `fieldconfiguration` answer 401 | 03 and 04 stop at the first 401 (exit 1); no group members, no project roles, no workflow export | token (Jan approved read scopes), 03, 04, 16 |
| B3 | 143 of 587 Jira accounts MANUAL: 111 app accounts, 32 people without a visible e-mail | their issues need `default_redmine_author_id`; app accounts should be ignored | 02 transform |
| B4 | No project selection: 01 creates every project, 10 stamps `issues_extracted_at` on every project although the JQL keeps 3 | adding a project later needs a manual reset of that column | 01, 10 |
| B5 | New users are LOCKED by default and 04 cannot add members | 283 issues "Assignee is invalid" (run 1) | 02 default, 04 |
| B6 | Retry needs SQL: rows in MANUAL or CREATION_FAILED are skipped on rerun, failed rows show as "[preserved] manual overrides" (25) though nobody edited them; same in 12 | every retry after a fix means `automation_hash = NULL` by hand | 10, 12 transform |
| B7 | Cascading fields (depending_enumeration): 08 stores value dependencies as labels, 10 sends enumeration ids | 44 issues 422 "is not included in the list"; on created issues the child value is empty (31 of 97 sampled values) | 08 push, 10 transform |
| B8 | Jira due date before the start date (start = created date) | 5 issues 422 | 10 transform |
| B9 | Unmapped assignee makes the issue MANUAL instead of unassigned | 4 issues not created | 10 transform |
| B10 | Description and parent are written in a second "final update"; until then the description is empty. One run of 10 leaves 373 issues READY_FOR_REWRITE; a second run is needed; issues whose parent failed keep an empty description | empty descriptions (GEOS-87: 0% of words) | 10 push |
| B11 | The final update writes the description as an update | every issue gets a journal "Description changed" by its author at the creation time | 10 push |
| B12 | Issues are created in the default status: new trackers have no workflow | 61 of 62 sampled issues "New" (run 1); fixed by opening workflows (a manual step) | 10, M8 |
| B13 | Redmine derives a parent's priority (and dates, done ratio) from its subtasks by default | OAC-6, OAC-52 differ from Jira | **Jan 2026-10-13: parent priority independent** on both targets (Redmine setting); verify expects Jira's priority |
| B14 | Attachments are stored as `{jiraId}__{name}` with spaces and special characters replaced | 25 of 62 sampled issues show other file names than Jira; keeps inline references unique | 09, 10; choice for Jan |
| B15 | `mailto:` links become `<local-part>` (domain lost); an HTML block (`<p>...`) around Markdown leaves `**` visible | GEOS-41, OAC-24, OAC-39, SIS-211 | conversion pipeline (not the HTML converter, see bench) |
| B16 | Mentions become `user#id`; ADF mentions inline code (M7); image alt text dropped | as planned for phase 7 | conversion |
| B17 | Journals of issues not created stay PENDING without a note; 31 warnings "changelog mentions attachments but none were mapped" (attachments deleted in Jira) | noise, no reason recorded | 11 |
| B18 | GEOxyz relation types need `redmine_issue_view_columns.local.rb` (production config, not in a repo); 9 "blocks" relations MANUAL ("blocked issue already closed"), 51 MANUAL because an issue is missing | 15 relations 422 without that config (test env now generates it from `custom_mappings`) | 12, test env |
| B19 | **7.0 only:** `redmine_tags` `redmine70-migration` dropped `POST /issues/:id/tags.json`; tags go through `issue[tag_list]` | all 32 tag updates 404 on 7.0 | 13 (detect, do not configure) |
| B20 | `redmine_view_issue_description` only accepts watchers with `view_watched_issues`; Redmine still answers 204 | 14 reports 777 SUCCESS while no watcher is stored (run 1) | 14 must read back |
| B21 | Downloads: 09 pulls 1.5 GB in about 800 s (about 1.9 MB/s); curl on the same gateway 17 MB/s | the SURV volume run is slow | 09 pull |
| B22 | An open subtask under a closed parent: 422 "An open issue cannot be attached to a closed parent task" on the final update, and the description in that same update is lost | OAC-44, OAC-47: no parent, empty description | 10 final update, `subtask_status_behavior` |
| B23 | Uploads happen before the issue exists (M9); for issues that fail, the upload stays orphaned | 208 orphans | 09, 10 |

**Gate for redmine_extended_api on 5.1 (2026-10-13): RED.** Jan merges `redmine70-migration` into
`main` once the 5.1 rerun is green; `main` was already at ae192d4 (code = 8bb01dc) when tested. Same
full run as run 2, 5.1 with ae192d4 instead of 132fff5: **0 of 426 issues created**, 422 times HTTP
500, verify FAIL (presence 0/75). Cause: `SystemStackError` between the Extended API's
`Issue.prepend` of `safe_attributes=` (issue_patch.rb:26, calls `super`) and `redmine_tags` `master`
(the 5.1 production branch), which chains the same method with `alias_method` (issue_patch.rb:33-34,
133); plugins load alphabetically, so the alias captures the prepended method and `super` loops. 7.0
is not affected (`redmine_tags` `redmine70-migration` prepends too). Reported to the coordinator for
session A; rerun the gate on the next commit.

## Results

(empty)
