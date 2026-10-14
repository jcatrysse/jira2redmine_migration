# Jira Cloud to Redmine: test migrations and script upgrade

Start a Claude Code session on this repository, branch `redmine70-migration`, with:

> Read CLAUDE.md and docs/MIGRATION-PLAN.md, then carry on with the first open item of the work
> list on branch redmine70-migration. Report to me in Dutch at the end.

With several sessions in parallel, the session Jan starts is the coordinator; see "Sessions".

This file is the plan and the memory of that work. Update it as you go: results, findings, what
is left. Written 2026-10-13 from a reading of the code and Jan's answers in the planning session.

## Handover to B2 (session B, 2026-10-13, about 19:45 UTC)

Session B stops writing here; **B2 is the only writer of this file and of the repository** from now
on. Session B stays available to the coordinator for questions.

**State.** Branch `redmine70-migration`, everything pushed, worktree clean; the commit carrying this
section is the hand-over point. `tools/qa.sh`: 74 PHPUnit tests, PHPStan level 8 and PSR-12 clean at
the last code commit. Nothing is running. Nothing is half done in the code. The test instances in
session B's container (5.1 reset to its baseline dump with Extended API 132fff5; 7.0 holding the p8
run) are lost with that container: B2 rebuilds them.

**Done.** Phases 2 to 5; phase 6 first part (`src/` library: client and database factories, central
retry; proof: a full 5.1 run recorded through `tools/testenv/record_proxy.mjs` before and after the
refactor, **3,918 Redmine requests, 0 differing**, `compare_requests.php` exit 0); phase 7 item 12
and item 14 (status under item 14); phase 8 (runbook, README, `docs/SCRIPTS.md`, CHANGELOG).

**Gate on Extended API 96fe96a (5.1).** Script results equal to 132fff5 (365 issues created, 48
failed, 5 manual, 2 update failed, 6 waiting; journals 939; relations 60/60; watchers 777;
attachments 367); 373 created issues, 11 parents, author_id and created_on equal to Jira for all. No
SystemStackError. **The verify step did not finish** (token revoked during the run): rerun the gate
with verify. The extended_api branch is now 9867918 (docs only on top of 96fe96a), so the gate stays
valid for that commit. Production 5.1 = `main` = 132fff5 (force-pushed back on Jan's instruction).

**Last verify on 7.0 (run p8, all fixes except B24, B14 and Resolved landed later): 6 failures**, all
explained, each handled in `lib/verify.php` (commit "Verify: open parents in the counts, parents
outside the run, mentions and issue links count as links"), **not yet confirmed by a rerun**:

| check | cause | handled |
|---|---|---|
| counts tracker/status, OAC (2) | the two open parents (OAC-19, OAC-42) are Resolved by Jan's rule, not their Jira status | counts expect Resolved for `[open-parent]` rows |
| fields: parent, GEOS-87 | parent in a project outside the run (READY_FOR_REWRITE by design) | reported as "parent outside the run", not a failure |
| description links, GEOS-41, GEOS-116, SIS-49 | Redmine renders mentions, `#id` and e-mail autolinks as links Jira does not count, and the reverse | mentions, issue links and e-mail autolinks counted on both sides |

**Open, in order** (the first five need the new token):

1. New token: write probe (decision 1, expect 401 "scope does not match"), cloudId into
   `/tmp/claude-0/cid` (read by `tools/testenv/env.sh`). **DONE (B2)**, see work list item 6.
2. 03, 04, 16 with the granular scopes; B25 (16 calls `GET /rest/api/3/workflow`, gone from the spec):
   see the real answer, then fix with a test. **DONE (B2)**: B25 to B32 under Findings.
3. Full runs 5.1 and 7.0 with all fixes (`tools/testenv/run_all.sh 51|70 LABEL`); verify green on
   both, B14 and Resolved included; rerun the stability check (10 twice, second run changes nothing). **DONE (B2)**: verify green on both, see item 14; the stability check found B35.
4. Extended API gate on 5.1 with verify (item above). **DONE (B2): green**, `redmine70-migration` c806a17
   (code 161fa52) on 5.1, fresh full run: verify 22 checks, 0 failures, results equal to `main` 132fff5.
5. WIKI through the conversion bench pipeline (Text conversion protocol); then phase 7 item 13
   (conversion fixes with PHPUnit cases) and bench step 3 (visual check with Jan).
   **B2:** WIKI run and bench done (Text conversion protocol, results B2); run findings B37 to B43,
   conversion fixes B42 and B44 with PHPUnit cases. **Open:** bench step 3 (visual check with Jan),
   a pipeline measurement after B41/B42/B44, the remaining defect classes (Outlook safelinks in mailed
   comments, status lozenges, a few headings).
6. SURV volume run: first implement the attachment sample test option (Jan's rule, under work list
   2; off by default; skipped files reported "skipped by sample" and expected-absent in verify).
   **B2: DONE**, option built (`attachments.test_sample`); SURV run on 5.1, see Results.
7. Phase 6 rest: the 8 helper functions still copied in every script (M1), CLI and config helpers,
   each step proven with identical recorded requests. **Open** (B2 did not start it; not Jira-bound).
8. Phase 9: final runs on both targets, numbers in "Results".

**Test environment, for `tools/testenv/setup.sh` in a fresh container.**
- Attach the private plugin repositories first (`add_repo`); 44 plugins for 5.1 in
  `plugins-5.1.tsv`. Takes about 15 minutes per target; disk is tight: setup clones with
  `--depth 1`; remove `.git` directories under vendor if bundler leaves full clones (4.9 GB once).
- Already handled by setup.sh: `activerecord-session_store` in Gemfile.local (5.1),
  redmine_custom_workflows v2.1.2 on 5.1, redmineup pinned to 1.1.4 (1.1.5+ duplicates a route),
  issue_recurring moved aside during `db:migrate` on an empty database, plugin directory = plugin id,
  `generate_secret_token`.
- `config/config.local.php` is gitignored and must be recreated. Session B's version reads everything
  from the environment: `jira.base_url = 'https://'.getenv('JIRA_SITE')`, username/api_token from
  `JIRA_EMAIL`/`JIRA_API_TOKEN`, `cloud_id` from `JIRA_CLOUD_ID`; `redmine.base_url`/`api_key` from
  `REDMINE_URL`/`REDMINE_API_KEY`; `extended_api` enabled, prefix `/extended_api`; `paths.tmp` from
  `J2R_TMP`; `migration.issues.jql` from `J2R_JQL` (default `project in (GEOS, SIS, OAC)`);
  `default_redmine_author_id` 1 for issues and journals; staging DSN from `STAGING_DSN`, user
  `jira2redmine`. `tools/testenv/env.sh 51|70` sets those variables (API keys from `setup5x.out`).
- Redmine runs on puma (`start.sh 51|70`, ports 3051/3070); `reset.sh` restores the baseline dump.
  Do not use `pkill -f` with a pattern that matches your own shell (it killed the shell, exit 144).
- run_all.sh: `RECORD=file.jsonl` records every Redmine request through the proxy (port 31xx);
  `manual_steps.rb` does workflows/members/restore. Apache + Passenger + mod_xsendfile for the large
  attachment test: `tools/testenv/apache-redmine70.conf`, `large_attachment_apache.sh`.
- Verify reports and run logs hold Jira data: keep them outside the repository.

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
| Phase | 2 to 5, 8 and 9 done (phase 9 green on 5.1 and 7.0, B2, 2026-10-14); 6 first part and 7 largely done; open: decisions for Jan, bench step 3, phase 6 rest |
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
8. **Manual steps stay scripted** (2026-10-13, after the runbook): `manual_steps.rb`
   workflows/members/restore; no Extended API import mode.
9. **Attachment prune off during the run** is a runbook step; no code change (2026-10-13).
10. **Project memberships (B31): option (a), a proposal Jan reviews** (2026-10-13, relayed by the
    coordinator). 04 proposes per project from the permission scheme: a group with Browse projects
    becomes a group member with Developer, a group with Administer projects Manager; users in the
    Administrators project role direct members with Manager; groups in project roles group members;
    atlassian-addons-project-access IGNORED; no expansion from a user to the user's groups. Jan
    reviews and edits `migration_mapping_project_role_groups` before `--confirm-push`; the
    automation hash keeps his edits; only READY rows are pushed. The `members` step only activates
    locked users. Done (B2, 2026-10-14): commits `f3443eb`, `17b8ec5`; review query in the runbook.

## Decided overnight (2026-10-13/14), for Jan to confirm

Taken by B2 on the coordinator's instruction (recommended option, no waiting); each can be reversed.
Per item: what was done, the alternatives, why this one.

- **Assignable user is proposed too** (B31 extension, coordinator agreed): a group with Assignable
  user in the permission scheme is proposed as group member with Developer, because Redmine assigns
  issues only to members. In the test projects it adds 2 groups that Browse projects does not give.
  Alternative: Browse projects only (those groups' issues then need B46's assignee rows).
- **A grant to an application role ("every licensed user", GSPP and ROV) is proposed for Redmine's
  built-in group "Non member users"** with the mapped role, as MANUAL_INTERVENTION_REQUIRED with a
  note: it gives the role to every logged-in user who is not a member, so it is pushed only once Jan
  sets READY. It does not make anyone assignable (Redmine assigns to User and Group members only).
  REST and Extended API create such a membership on 7.0 (201). Alternatives: (b) READY at once,
  (c) only a note (the earlier behaviour), (d) every licensed user as a direct member (expansion).
- **B46, option (a) with the coordinator's refinement: an issue's assignee that no membership covers
  is proposed as a direct member** in the same review table, role `migration.roles.assignee_only_role`
  (default Developer; alternative: a dedicated assignable role with minimal permissions, so a moved or
  departed person keeps the assignment without access Jira did not give), note "Assignee only, not in
  the project's permission groups", status READY (without these rows Redmine refuses the issues; Jan
  reviews them before the push like every proposal). 04 now runs after the issues are staged; the
  members step (activate locked users) runs before 04's push. Without it Redmine refused 10 issues in GEOS, SIS and OAC and 47
  in WIKI ("Assignee is invalid"): Jira keeps an assignee who lost the permission. Alternatives:
  (b) 10 creates such an issue unassigned and keeps the Jira assignee in the mapping notes and the run
  report (the assignee is lost in Redmine); (c) the old members step, limited to assignees. (a) keeps
  the assignee and follows Jan's review model.
- **B50, option (a): a watcher without access to the project is not migrated, as in Jira.** Redmine
  drops a watcher who is not a member (204, not stored); with Jira's membership model that user has no
  Browse projects in Jira either and cannot see the issue there (38 in the GEOS/SIS/OAC run, 372 in the
  WIKI batch). 14 notes `[no-access]` and keeps the row FAILED, so a rerun adds the watcher once a
  membership is given; verify expects them absent. Alternative: (b) watcher-only members, proposed like
  B46's assignees (gives access Jira does not give).
- **Automation rules: no export through the automation API.** 16 logs why it skips them
  (`AutomationRules::SKIPPED`). Recommendation: export the rules by hand from Jira (Export rules,
  JSON), keep the file outside the repository and use it as the checklist for what to rebuild in
  Redmine (runbook, section 0). Alternative: an export through Atlassian's automation API (own
  token and scope, not the platform API), to rebuild them as custom workflows.
- **B37, option (a): a select value Jira no longer offers is added to the Redmine field.** After the
  run it is retired per Redmine format: an enumeration (key/value, 08's format for every select) value
  is made inactive, a plain list value is removed from the possible values. Checked on 5.1 and 7.0
  (`tools/testenv/retired_values.rb/.mjs`, browser and REST), the same on both targets and for both
  formats: the issues that keep the value show it, the edit form still offers it on those issues
  (selected), a save keeps it (browser saved, REST PUT 204), and no other issue can get it (REST 422
  "is not included in the list"). 08's transform lists every such value with its issue count
  (`[unoffered]` lines, run summary). Runbook section 2. Alternatives: (b) map the value to an
  existing one by configuration, (c) leave the field empty on those issues.
- **B38, option (a): "Allow cross-project subtasks: with all projects" (and B39: cross-project issue
  relations) as a runbook step that stays on after the migration.** An issue whose parent is in a
  later batch waits READY_FOR_REWRITE and is linked by the batch that migrates the parent's project;
  every 10 run lists the issues still waiting with the parent's key, per parent project, so a parent
  that never arrives is seen. Batches run with every project migrated so far plus the new ones (the
  jira phases of 07 and 08 replace their staging tables with the run's projects). Runbook section 1.
  Alternatives: (b) a relation instead of a parent across projects, (c) leave such parents out and
  log them.
- **Service desk fields "Organizations" and "Request participants"**: 0 values in GEOS, SIS, OAC and
  WIKI (measured in the 7.0 staging); 08 keeps them MANUAL. Recommendation: Request participants become
  watchers (the nearest Redmine notion: people who follow the request and get its mail), Organizations
  a key/value list field from the used values (as label fields, B45); build it when a project in scope
  uses them. Alternatives: (b) a text field with the names, (c) not migrated.
- **Conversion choices (quality work):** a status lozenge becomes inline code (`` `Closed` ``): a
  separate label close to Jira's box, which cannot mix with bold or links next to it as `**` can
  (alternative: bold); a link to a migrated issue with its own text reads "text (#123)" (the text was
  lost), with the key as text it stays "#123"; a link to an issue outside the migration keeps its Jira
  link with the key as text, readable after Jira closes and clickable while it is open (alternative:
  the key as plain text); Outlook safe links in mailed comments become the link itself (the wrapper
  expires and carries the recipient's address).

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
   **New token (granular read scopes), B2, 2026-10-13:** `GET /rest/api/3/myself` 200;
   `PUT /rest/api/3/issue/ZZPROBE-999999999` and `POST .../comment` 401 "Unauthorized; scope does not
   match"; `role`, `group/member`, `workflowscheme`, `screens`, `workflow/search`, `issuetypescheme`,
   `issuetypescreenscheme`, `screenscheme`, `fieldconfiguration`, `fieldconfigurationscheme`,
   `permissionscheme` 200; the legacy `GET /rest/api/3/workflow` 401.

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
    **First part DONE** 2026-10-13: `src/` (ClientFactory, RetryPolicy, ConnectionFactory), QA tools;
    a full 5.1 run recorded before and after: 3,918 Redmine requests, 0 differing. Rest: M1 helpers.

**Phase 7, fixes**

12. Mentions to `@login` (decision 4), in both conversion paths.
    **DONE** 2026-10-13: HTML path and ADF path; `user#id` stays only for users without a known login.
13. Conversion fixes from the bench, each with a PHPUnit case built from an anonymised
    reproduction of the Jira input.
14. Every finding from the baseline: fixed, or a line saying why not.
    **Status 2026-10-13**, each fix with a PHPUnit test (73 tests) unless noted:
    fixed B1, B3, B4 (`migration.issues.projects`), B6 (link rewrite; MANUAL rows judged again, the
    latter checked end to end only), B7, B8, B9, B10/B11/B22, B13 (setting), B14 (Jan's rule), B15,
    B19, B20, B24, F5; B21 is configuration (`download_concurrency` 6: 760 s to 96 s); open subtask
    under a closed parent: parent gets "Resolved" (Jan). Not code: B2 (token scopes, waiting for Jan's
    new token), B5/B12 (manual steps, item 15), B16 alt text (kept), B17 and B23 (noise; reported),
    B18 (production config of redmine_issue_view_columns, documented in the runbook).
    Run on 7.0 with the fixes (before B24, B14, Resolved and the parent priority setting): 420 of 426
    issues, every tracker/status count equal to Jira, presence 75/75, watchers 960 stored.
    **Full runs with all fixes, B2, 2026-10-13** (`run_all.sh 51|70 b2-full1`, new token, 03 in two
    passes, 04 with push, Extended API 132fff5 on 5.1 and 9867918 on 7.0): **verify green on both**, 21
    checks, 0 failures (presence 75/75, counts 30/30, comments 254, attachments 75/75 and sha256 136,
    custom fields 340, relations 35, watchers 75, every field 75/75). Both targets the same: 426 issues
    (422 created, 4 READY_FOR_REWRITE: parent outside the run), 1,277 journals, 576 attachments, 71
    relations (49 MANUAL: other side outside the run), 960 watchers, 38 project role memberships. Green
    after reruns that needed no SQL: 12 for 17 relations (relation types missing in the rebuilt test
    instances, B34), 09/10/11 on 7.0 for GEOS-23 (B33). Stability check (10 to 14 a second time):
    10, 12, 13, 14 change nothing (13 and 14 caught up GEOS-23); 11 pushed every journal again (B35, fixed).

**Phase 8, workflow and documentation**

15. Runbook for a full migration: order, commands, what to check after each step, where the
    decisions live. Replace the manual steps of M8 where it is worth it, each as a choice for Jan
    with options (examples: upload and attach per issue so prune can stay on; an Extended API
    import mode that skips workflow and initial-status checks and accepts locked users, admin
    only, `/extended_api` only).
    **DONE** 2026-10-13; Jan: manual steps stay scripted, prune off as a runbook step (decisions 8, 9).
16. README rewritten from the runbook; CHANGELOG back to a changelog.
    Status 2026-10-13: done. Runbook in `docs/RUNBOOK.md` (sequence, checks, manual steps with
    `manual_steps.rb` workflows/members/restore, choices for Jan, Apache appendix). README is a short
    overview; the per-script detail moved to `docs/SCRIPTS.md` (subtask, watcher and attachment name
    notes brought up to date); CHANGELOG lists the changes of this branch, its manual steps (M8) are
    in the runbook. Open: the Extended API import mode (choice for Jan, session A).

**Phase 9, final runs**

17. Full test run on both targets with the final code; verify report green; numbers in "Results".

## Sessions

The work runs in parallel sessions that write **disjoint repositories**, so they never conflict.
Exactly one session writes this file: session B until 2026-10-13, B2 from then on (see Handover).

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
**Results B2, 2026-10-13**: a run on 7.0 with GEOS, SIS, OAC and WIKI (1,883 issues, 3,535 comments,
`attachments.test_sample`: 1,103 of 1,413 files), every staged item rendered by Redmine 5.1:

| path | items | without loss | B (earlier) |
|---|---:|---:|---:|
| html (converter only) | 4,648 | 4,572 (98.4%) | 4,630 (99.6%) |
| adf | 4,648 | 4,270 (91.9%) | 2,098 (45.1%) |
| pipeline (what is pushed) | 3,842 | 3,702 (96.4%) | 1,057 of 1,139 (92.8%, three projects) |

- Pipeline losses (140): 98 only "fewer than 99% of the words" (Jira keys rewritten to `#id` by design,
  names of users that are not migrated, a few link labels), 22 with `](` visible, 7 missing images, 5
  with raw HTML tags, 6 with `**` visible, 1 heading and quote lost. Weakest constructs in the pipeline:
  issue link 144/215, status lozenge 3/14, heading 5/12.
- `](` visible: link markup that Jira showed as text (`[label|url]` and `[label](url)` typed by a user)
  half converted by the link rewrites, and URLs with parentheses: **fixed, B42** (8 descriptions and
  part of the 14 comments; the rest are e-mail-originated comments with Outlook safelinks). Avatars
  reached Redmine in 3 issues and 3 journals: descriptions fixed with B41, journals open.
- The HTML path alone dropped from 99.6% to 98.4% since B's measurement: the mention conversion now
  writes `@login`, which the bench renders unresolved on the html path (the pipeline path resolves it).
- Not yet done: a pipeline measurement after B41/B42 on a fresh run, bench step 3 (visual check with
  Jan), the remaining defect classes as PHPUnit cases.

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
- B14 measured on 7.0 (2026-10-13): Redmine stores, lists and downloads same-named attachments
  correctly, but an inline image resolves to the latest attachment of that name (description showed
  #578 instead of #577). **Jan: original names, `{jiraId}__` only for a name the issue already has.**
  Implemented; with that rule all four inline references of the test issue resolve to the right file
  on 7.0 and on 5.1 (`tools/testenv/duplicate_names_test.php --rule`); without it both Redmines show
  the second file for both references.
- Open subtask under a closed parent, **Jan:** link it and give the parent the open status "Resolved"
  (exists and is open on 5.1 and 7.0, id 3). Implemented as the default rule; parents noted
  `[open-parent]` with their Jira status; the verify tool expects Resolved for them. In the baseline
  data: OAC-19 and OAC-42.
- ~~Granular read scopes~~ **done**: the new token works (B2, work list item 6). (Approved by Jan 2026-10-13: read:group:jira, read:user:jira,
  read:project-role:jira, read:workflow:jira, read:workflow-scheme:jira, read:issue-type-scheme:jira,
  read:issue-type-screen-scheme:jira, read:screen:jira, read:screen-scheme:jira, read:screen-tab:jira,
  read:screenable-field:jira, read:field-configuration:jira, read:field-configuration-scheme:jira,
  read:avatar:jira): waiting for the new token; then write probe again and rerun 03, 04, 16.
  Correction (coordinator, checked against Atlassian's OpenAPI spec 2026-10-13): a granular token needs
  every scope an operation lists; the 14 missed 7. Jan gets the union of 21 granular scopes plus
  read:jira-work and read:jira-user (list in the runbook, step 0).
- 16 against the current spec (to confirm with the new token): `GET /rest/api/3/workflow` is gone from
  the spec (16 calls it after workflow/search; expect an error that stops the export, fix with a test
  once the real answer is seen); workflow/search, fieldconfiguration and fieldconfigurationscheme are
  deprecated; `/rest/api/3/automation/rules` is not a Jira platform endpoint (16 already skips it on
  404). Logged as B25. **Confirmed and fixed (B2)**: see B25 to B28.
- ~~Project memberships (B31)~~ **Jan: option (a)**, see decision 10.
- ~~B37, B38/B39~~ and ~~automation rules~~: decided overnight, see "Decided overnight" (for Jan to
  confirm).

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
| B25 | 16 calls `GET /rest/api/3/workflow`, which is no longer in the Jira Cloud spec; workflow/search, fieldconfiguration and fieldconfigurationscheme are deprecated (coordinator, from the OpenAPI spec) | seen with the new token: 401 "scope does not match", the export stops; so do `GET /issuetypescheme/{id}` (no GET in the spec; 405 with a classic token, skipped) and `/automation/rules` (not a platform endpoint) | **fixed** (B2): workflow/search only, the scheme's issue types from `/issuetypescheme/mapping`, no automation call; 16 exit 0 |
| B26 | workflow/search returns the name in `id.name` (an object); 16 read `name` | all 64 workflows stored as "unknown", one row left | **fixed**, `src/Jira/WorkflowSearch.php`; with `expand` the export holds transitions (856), rules and statuses |
| B27 | Paging advances by the requested page size; Jira caps workflow/search at 50 when asked for 100 | 14 of 64 workflows skipped | **fixed** in 16 (`src/Jira/Paging.php`); 11 pages comments and changelog the same way, Jira honours 100 there today (checked), latent |
| B28 | `issuetypescheme/project` nests the scheme (`issueTypeScheme.id`, `projectIds`); 16 read flat keys | 0 project to scheme rows | **fixed**, 15 rows |
| B30 | Jira returns project role URLs on the gateway; 04 uses their path, the gateway rewrite added `/ex/jira/{cloudId}` a second time | all 45 role requests 401, 04 extracted no actor and exited 0 with warnings | **fixed** in `lib/jira_gateway.php` (a prefixed path keeps one prefix), 278 actors |
| B31 | 04 gave a project role held by a user to every group the user is in | one admin in a 254-member group made it admin (Manager) of 8 projects, 143 group assignments | **fixed** for what both options share: user actors are direct members, group actors their group; `migration.roles.mappings` (Administrators => Manager, add-ons role ignored); app accounts IGNORED; a pushed membership stays recorded on a rerun. Result: 36 users and 2 groups (max 6 members) Manager, as in Jira. Base access: **Jan option (a)**, decision 10: proposals from the permission schemes, reviewed before the push; the push adds a role to an existing membership (PUT) instead of a second POST (422). 5.1: 89 proposals, 2 hand edits kept on a rerun, 88 pushed, 0 failed |
| B32 | 03 adds group members only once the groups exist; the runbook ran it once | 754 members AWAITING_GROUP, no group had members | runbook and `run_all.sh`: a second pass (551 members added; 203 wait for accounts that are not migrated) |
| B33 | A download that fails (a dropped tunnel to api.atlassian.com, two runs in parallel) is FAILED; the transform requeued it and cleared the reason; 10 holds the issue back | 7.0: 2 of 576 files, GEOS-23 not created, no visible cause | **fixed**: 09 tries failed files once more in the same pull, prints each failure with its reason and keeps it when requeued (`src/Attachments/PullRetry.php`); 10 no longer waits for a switched-off attachment (`IssueAttachmentState`) |
| B34 | 12 marks a refused relation CREATION_FAILED without its automation hash | every later transform takes it for a hand edit ("preserved manual overrides") and never retries; 17 relations after the relation types were added | **fixed** (`src/Relations/RelationState.php`); proof on 5.1: 17 failed, a plain rerun created all 17. Test env: `setup.sh` now installs the relation types (B18) |
| B35 | **11 pushes every journal again on each rerun** (also on `main`): the transform set every journal of a created issue to READY_FOR_PUSH, pushed ones included; FAILED journals were never retried | stability check: +1,277 duplicate journals on 5.1 and 7.0; verify still passed (each Jira comment was found) | **fixed** (`src/Journals/JournalStatus.php`): pushed journals stay SUCCESS, FAILED and SKIPPED are judged again; proof on 5.1: rerun pushed 0 comments, journal count unchanged. verify now fails on a journal present twice (51 of 75 sampled issues on the duplicated instance) |
| B36 | verify read the `[open-parent]` note, which the push clears once the issue exists; verify's own clients had no retry | OAC 2 count failures after a full run; one verify run stopped on a dropped connection | **fixed**: open parents from the mapping (proposed status differs from the mapped Jira status), clients from `ClientFactory` |
| B37 | A select option Jira no longer offers stays on old issues (WIKI: one option on 294 issues; the list has a renamed one) | the Redmine field had only the offered values; 10 held all 294 issues back as MANUAL | **fixed** (option a, decided overnight, for Jan to confirm): 08 adds used values to the field (`UsedValues`) and lists them (`[unoffered]`), taken in the usage phase once 10 staged the issues; 10 reads the options from 08's Redmine snapshot, so a value added to an existing field by hand is used too |
| B38 | A Jira parent in another project (GEOS issues under WIKI epics) | 422 "Parent task is invalid": Redmine allows subtasks within a project tree by default | Redmine setting "cross-project subtasks: with all projects" (option a, open for Jan); test runs set it |
| B39 | Jira links across projects | 29 relations 422 "doesn't belong to the same project" | Redmine setting "allow cross-project issue relations" (with B38, open for Jan); test runs set it |
| B40 | A Jira link between an issue and its own parent or subtask | 6 relations 422 | **fixed**: IGNORED with a note (the hierarchy holds it) |
| B41 | 11 rewrote 10's issue descriptions in staging after 10 pushed them | avatars stayed in Redmine (3 issues); issues not created yet got a broken hash and stayed "manual overrides" (17) | **fixed**: 10 cleans descriptions before its hash and push, 11 no longer touches them |
| B42 | Link markup Jira showed as text, URLs with parentheses | `](` visible (22 items) | **fixed** in descriptions and comments, PHPUnit cases from anonymised input |
| B43 | 08's purge of mappings without a Jira field deleted the synthetic `cascading_parent:` rows on every transform; after a rerun of 08 nothing recreated them | 10 lost the parent field of 3 cascading fields; issues created after the rerun lost their parent value (41 verify failures in the healed WIKI run) | **fixed** (`MappingKeys`) |
| B44 | The spacing clean-up joined digits after a reference; two clean-ups turned "user 5" into a mention | a mention followed by a number pointed to another user ("user#332 4 lines" became "user#3324": 8 journals in the SURV run, 7 comments in the data); "#12 3" would become "#123" | **fixed**, PHPUnit cases from anonymised input |
| B45 | 08 took the values of app fields without allowed values (label manager fields) only in the jira phase, before 10 staged the issues | 5 fields MANUAL, not migrated | **fixed** with B37 (usage phase): 34 fields created instead of 30. "Organizations" and "Request participants" (service desk) are now seen and MANUAL; not migrated before either |
| B46 | With 04's memberships from Jira's model (B31 a), Redmine refused issues whose assignee no membership covers | "Assignee is invalid": 10 issues in GEOS, SIS, OAC (5.1), 47 in the WIKI batch (7.0); Jira keeps an assignee who lost the permission | **fixed** (option a, decided overnight): 04 proposes such an assignee as a direct member (Developer) in the review table; 04 runs after the issues are staged. Every refused (project, assignee) is in the proposal: 6 of 6 (5.1), 9 of 9 (7.0) |
| B47 | A later batch: a select value its issues hold never reached a field created by an earlier batch | 294 WIKI issues MANUAL ("could not be normalised"); 08's push updated an existing field's projects and trackers, not its values | **fixed**: 08's push adds the missing values to a field the migration created (Extended API) and refreshes the snapshot 10 reads; a field that existed before is listed, not changed |
| B48 | A Jira user page cut off mid-transfer (cURL 18) | 02's extraction stopped in the WIKI batch; the retry covered failed connections only | **fixed**: every transport failure of a GET is retried |
| B49 | Heading anchors (`<a name>`) left as HTML in the Markdown | the link passes wrapped the whole text in `<p>`: Redmine showed `###`, `**`, `&gt;` (13 of 2,214 texts) | **fixed**: anchors dropped before conversion; 3 texts left (literal MediaWiki HTML, another class) |
| B50 | With 04's memberships from Jira's model (B31 a), Redmine drops watchers who are not members of the project | 48 FAILED rows ("204 but not stored") in the 5.1 run, 372 in the WIKI batch | **handled** (option a, decided overnight): 14 notes `[no-access]` when the user is no member, verify expects them absent; all 38 rows of the final 5.1 run are `[no-access]` |
| B51 | 08's snapshot update used CAST(... AS JSON), which MariaDB refuses | B47's fix added the value in Redmine but 10 read the stale snapshot: the 294 WIKI issues stayed MANUAL in the proof batch | **fixed**: JSON_EXTRACT(:e, '$'); 08 compares with the field's values in Redmine before adding |

**Gate rerun on 5.1 with 96fe96a (recursion fix 22e3eb8 + parent author fix f9bf1e7), 2026-10-13:**
no SystemStackError; script results equal to 132fff5 (issues 365 created, 48 failed, 5 manual, 2
update failed, 6 waiting; journals 939; relations 60/60; watchers 777; attachments 367). Parent fields:
373 created issues, 11 of them parents, author_id and created_on equal to Jira for all. The verify
step itself stopped halfway: the Jira token was revoked during the run (401 Unauthorized on every read
since about 18:40).

**Gate for redmine_extended_api on 5.1 (2026-10-13, first run): RED.** Jan merges `redmine70-migration` into
`main` once the 5.1 rerun is green; `main` was already at ae192d4 (code = 8bb01dc) when tested. Same
full run as run 2, 5.1 with ae192d4 instead of 132fff5: **0 of 426 issues created**, 422 times HTTP
500, verify FAIL (presence 0/75). Cause: `SystemStackError` between the Extended API's
`Issue.prepend` of `safe_attributes=` (issue_patch.rb:26, calls `super`) and `redmine_tags` `master`
(the 5.1 production branch), which chains the same method with `alias_method` (issue_patch.rb:33-34,
133); plugins load alphabetically, so the alias captures the prepended method and `super` loops. 7.0
is not affected (`redmine_tags` `redmine70-migration` prepends too). Reported to the coordinator for
session A; rerun the gate on the next commit.

## Results

**SURV volume run, 5.1, B2, 2026-10-13** (`J2R_PROJECTS=surv J2R_TEST_SAMPLE=1 run_all.sh 51 b2-surv`,
code before B44): 1,494 issues, all created (10 in 479 s); attachments 6,323 in Jira, 4,306 in the sample
(0.98 GB, pull 518 s, push 182 s), 2,017 skipped by sample (97.7 GB); journals 8,674 pushed and 12,882
changelog entries without content skipped (11 in 2,592 s, Jira extraction included); relations 151
created, 309 to issues outside the run, 3 parent/subtask links ignored (B40); watchers 3,750. A second
pass of 11 to 15 (by accident, see below) created nothing twice: verify "journals: no duplicates"
25/25. Verify, 50 issues: 3 failures before the verify fixes, all explained: one mention joined with a
number (B44, fixed after this run), one empty rich text field and one checksum that matched on every
later read (verify fixes). By accident `run_all.sh` was edited while this run used it; bash then
repeated 13 and 14 and ran 11 to 15 once more.

**Phase 9: final runs, B2, 2026-10-14 — green on both targets.** Scripts at b556cc6, Extended API
`redmine70-migration` c806a17 on both (the version Jan merges into `main` once the gate is green; the
gate is green), GEOS, SIS and OAC, no attachment sample, fresh instances and staging
(`STABILITY=1 run_all.sh 51|70 b2-phase9`):

| | Redmine 5.1 | Redmine 7.0 |
|---|---|---|
| verify (25 issues per project, 22 checks) | **PASS, 0 failures** | **PASS, 0 failures** |
| presence / counts tracker+status | 75/75, 30/30 | 75/75, 30/30 |
| comments, journals without duplicates | 254, 75/75 | 254, 75/75 |
| custom fields with a value | 380 | 380 |
| attachments name+size / sha256 | 75 / 136 | 75 / 136 |
| relations, watchers | 35, 75 | 35, 75 |
| issues created | 422 (+4 waiting for a parent outside the run) | 422 (+4) |
| journals pushed / skipped (no content) | 1,277 / 2,937 | 1,277 / 2,937 |
| attachments | 576 | 576 |
| relations created / outside the run | 71 / 49 | 71 / 49 |
| watchers stored | 960 | 960 |
| custom fields created | 34 | 34 |
| project memberships from roles (04) | 38 | 38 |
| second pass of 08 to 15 | **nothing changed** | **nothing changed** |
| script time, structure to 16 | 1,278 s | 1,255 s |

Longest steps: 11 (journals, Jira extraction included) about 460 s, 14 (watchers, read back) about
185 s, 09 (pull 95 s, push 136 s for 1.5 GB), 10 about 110 s. Differences between the targets are
Redmine's own: 7.0 writes a journal for a tag change (1,535 journals and 258 details against 1,489 and
212) and adds 27 watchers itself (1,140 against 1,113).

What is not covered by these runs: the manual steps and decisions still open for Jan (memberships
B31, select values B37, cross-project settings B38/B39, automation rules, service desk fields), the
Apache configuration of the production servers (runbook appendix), and phase 6 rest (M1).

**Phase 9 again with the overnight code, B2, 2026-10-14 (night) — green on both targets.** Scripts at
9e64fe7 (5.1) and 632a000 (7.0; the difference is the bench and verify measurement only), Extended API
c806a17, GEOS, SIS, OAC, fresh instances and staging, `STABILITY=1 run_all.sh 51|70 b2-final2|b2-final`.
New since the run above: memberships from Jira's model (B31 a) with assignee-only members (B46) and
04 after the issue extraction, watchers without project access (B50), the conversion fixes, retry on
cut transfers (B48), the B37/B38 lists, 16's workflow scheme per project.

| | Redmine 5.1 | Redmine 7.0 |
|---|---|---|
| verify (25 issues per project, 23 checks) | **PASS, 0 failures** | **PASS, 0 failures** |
| issues created | 422 (+4 waiting: parents in GSPP 1, WIKI 3) | 422 (+4) |
| journals pushed / skipped | 1,277 / 2,937 | 1,277 / 2,937 |
| attachments, relations created | 576, 71 | 576, 71 |
| memberships pushed by 04 (created / role added / present / failed) | 79 / 7 / 10 / 0 | 79 / 7 / 10 / 0 |
| of which assignee-only members (B46) | 7 | 7 |
| watchers stored / without project access (B50) | 922 / 38 | 922 / 38 |
| verify: watchers without access, expected absent | 7 of 7 | 7 of 7 |
| second pass of 08 to 15 | **nothing changed** | **nothing changed** |

Watchers stored fell from 960 to 922: the 38 are users without Browse projects in Jira (not in the
groups the permission schemes name), whom Redmine refuses as watchers; all 38 rows say `[no-access]`.

**Workflow proposal (5.1 staging of this run, `tools/workflow_proposal.php`).** 26 Jira workflows over
74 project and issue type pairs (all 15 projects 01 created): 827 rows proposed, 176 to review. Rules
Redmine cannot apply, by rows: transition screens 96, a permission condition given to every role of
the project 68 (CREATE_ISSUES 48, RESOLVE_ISSUES 18, ASSIGNABLE_USER 2), the post function "assign to
the current user" 12. Not proposed: transitions from or to 9 Jira statuses without a Redmine status
(not in Jira's status list that 05 reads, and no staged issue has them), transitions limited to groups that are not members of the project
(TEST, ICT, SURV, GEOS, DOC). Five trackers are fed by 2 to 6 Jira workflows: Redmine has one workflow
per tracker, so the proposal is their union. A hand edit (IGNORED) stayed on a rerun. Nothing is sent
to Redmine: how to apply it is Jan's decision (open question).

**Non member group (application-role grants), 5.1.** The final run proposed GSPP's and ROV's grants to
every licensed user as 4 rows for "Non member users", MANUAL_INTERVENTION_REQUIRED. An anonymised grant
on OAC, set READY as Jan would, was pushed (membership "Non member users", Developer); a logged-in
non-member, non-admin user then has the role's rights and is not assignable, as the note says. The
test memberships were removed again.
