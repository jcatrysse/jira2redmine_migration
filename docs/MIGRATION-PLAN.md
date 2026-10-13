# Jira Cloud to Redmine: test migrations and script upgrade

Start a Claude Code session on this repository, branch `redmine70-migration`, with:

> Read CLAUDE.md and docs/MIGRATION-PLAN.md, then carry on with the first open item of the work
> list on branch redmine70-migration. Report to me in Dutch at the end.

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
| Phase | 0, prerequisites (see work list) |
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
2. Pick 2 or 3 small Jira projects for the tests, varied: one with rich formatting, one with
   attachments including a large video, one with many custom fields. Write their keys here.
3. Confirm the 5.1 plugin set: which plugins, and which branch of each, production runs today.

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

**Phase 4, baseline**

7. Run every script, otherwise unchanged, on the test projects, to 5.1 and to 7.0. Record every
   failure, warning and manual intervention in "Findings". Fix nothing yet.

**Phase 5, measuring tools**

8. Verify tool: compare Jira and Redmine after a run. Counts per project, tracker and status;
   for a sample of issues the description, journals, attachments (name, size, checksum),
   watchers, relations and custom field values. Output a pass/fail report. From here on "green"
   means this report.
9. Text conversion bench (decision 7), see "Text conversion protocol".
10. Large attachment test (decision 3), see "Large attachment protocol".

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

- Test projects (work list 2) and the 5.1 plugin set (work list 3).
- PHP version on the machine where the scripts will run in production.
- Staging database: stay on MySQL or move to PostgreSQL (decide after the baseline).
- Worklogs to time entries: in scope or not.
- Which manual steps to automate (decide after the baseline, per step).

## Findings

(empty until the baseline run)

## Results

(empty)
