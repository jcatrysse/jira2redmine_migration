# Changelog

All notable changes to this project. Steps to take before and after a migration are in
[docs/RUNBOOK.md](docs/RUNBOOK.md); the findings (F, B) are in [docs/MIGRATION-PLAN.md](docs/MIGRATION-PLAN.md).

## [Unreleased] (branch redmine70-migration)

### Added
- `J2R_JIRA_TAPE` (test runs): records every Jira answer of a run or plays one back without Jira, so the runs before
  and after a refactor read the same Jira (`src/Http/JiraTape.php`, `tools/testenv/run_all.sh`).
- Redmine 7.0 as a second target next to 5.1; both tested with the same projects.
- Jira scoped API tokens: with `jira.cloud_id` set, every call goes through the gateway
  `https://api.atlassian.com/ex/jira/{cloudId}`.
- `migration.issues.projects` selects the projects of a run (B4).
- Shared library in `src/` (namespace `Jira2Redmine\`): one factory for the Jira and Redmine clients
  and the staging database, central retry on 429 and 503 with `Retry-After` (decision 5).
- `tools/verify.php`: compares Jira and Redmine after a run and reports pass or fail per check.
- `tools/qa.sh`: php -l, PHPUnit, PHPStan level 8, PSR-12; tests in `tests/`.
- `tools/testenv/`: reproducible Redmine 5.1 and 7.0 test instances, full test run, manual steps
  (open workflows, activate users, restore), recording proxy for refactor proofs, large attachment
  and Apache tests, conversion bench.
- docs: runbook, script reference, migration plan.
- `migration.roles.mappings`: Jira project role name to Redmine role (default `Administrators` =>
  `Manager`; `atlassian-addons-project-access` ignored).
- `attachments.test_sample` (test option, off): only attachments up to 512 KB plus the largest per
  file extension up to 20 MB; the rest is noted "skipped by sample" and expected absent by verify.
- verify: fails on a journal present twice; reads open parents, unmapped assignees, empty rich text and
  empty label values the way the scripts migrate them; downloads a checksum mismatch again.
- `tools/testenv/run_all.sh`: `STABILITY=1` reruns 08 to 15 and compares Redmine's counts; `BATCH=1`
  runs a later batch on the previous state; the summary lists B37 and B38 lines.
- 04 proposes project memberships from each project's permission scheme: groups with Browse projects
  or Assignable user as Developer, Administer projects as Manager (B31, Jan option a). New staging
  table `staging_jira_project_permission_grants`. The proposals are reviewed in
  `migration_mapping_project_role_groups` before the push; hand edits stay (runbook, section 1).
- 08 lists the select values Jira no longer offers, with the number of issues and how to retire them
  per Redmine format (`[unoffered]`, B37); `tools/testenv/retired_values.rb/.mjs` check what issues do
  with a retired value.
- 10 lists the issues waiting for a parent outside the run, per parent project (`[waiting]`, B38).
- 16 logs why automation rules are not exported.
- Bench `--details`: the full measure per item and path.
- Temporary migration role (Jan, decision 12): the `members` step makes every migrated user a member of
  every migrated project with `Migration (temporary)`, on top of 04's memberships; `restore` removes it.
  04's assignee-only (B46) and watcher-only (B50) members are off by default
  (`migration.roles.assignee_only_members`, `watcher_only_members`). `run_all.sh RESTORE=1` proves it.
- Workflows (Jan, decision 13): `tools/workflow_proposal.php` writes three review tables (transitions,
  field permissions from required transition screen fields, "assign to the current user" scripts);
  `tools/workflow_plan.php` and `tools/apply_workflows.rb` apply the approved rows (preview, then
  `--confirm-push`). 04 stages every permission holder, 16 the field configuration items.
- Workflows (Jan, decision 14): `migration.workflows.group_roles` settles a transition Jira limits to a
  group that is not a member, per project and group: `G` makes the group a member with its own role
  (04 proposes it, its push creates the role through the Extended API: Edit issues only, not assignable),
  `R` gives it to the roles the group's users hold, `ignore` marks the review row IGNORED; a group not
  listed stays a review row. `tools/testenv/group_role_proof.rb` shows it on a real Redmine.
- Configured outcomes for what Jan leaves out (decisions 19, 20, 22): `migration.workflows.dropped_conditions`
  (a Jira condition left out per project, the transition proposed without it), `migration.custom_fields.not_migrated`
  (field types IGNORED, not MANUAL: the service desk fields), `migration.projects.not_migrated` (TEST).
- Start-up check in `bootstrap.php`: PHP 8.2 or newer and the required extensions, with a clear message
  (decision 23); runbook section "Prerequisites" (PHP, a temporary MySQL/MariaDB for staging, its removal).
- `tools/testenv/core.sh` and `plugins.sh`: bring the test instances' core and plugins to their branch heads
  (forced refspec) and print the hashes.
- Shared code (M1): `Jira2Redmine\Redmine\ExtendedApi` (prefix, paths, availability check, error summary) for
  04 to 12, and `Jira2Redmine\Cli\Phases` for the phase selection of all 16 scripts.
- Later batches: 08 adds new select values to fields an earlier batch created (B47) and enables them for
  the new projects and trackers (B53); `tools/relink_issue_links.php` rewrites links to issues of a later
  batch in texts Redmine did not change (B52), and its check counts the texts that still link a migrated
  issue (0 after the relink; Jan chose batches plus relink); links with an old issue key are resolved (B54).
- `tools/testenv/visual_items.php`, `visual_check.mjs`: Jira's rendering next to Redmine's page per
  curated issue, description and comments (bench step 3; the output stays outside the repository).

- `migration.priorities.mappings` (Jira name => Redmine name) and `migration.priorities.create_missing`
  (decision 35); `migration.workflows.open_workflow_roles` (decision 33); `tools/config_value.php` prints a
  `migration.*` value as JSON for the rails runner steps.
- docs/PRODUCTION.md: the procedure for the rehearsal and production (upgrade to 7.0, then the migration in
  one run of all 14 projects with snapshots at its three pauses, decision 39; the four batches as the fallback;
  the prompt for the local session).
- Jira's history of status, assignee and priority as Redmine journals (Jan, decision 41): 11's phase `history`
  (a default phase) writes them with its push, through the Extended API's history endpoint (`b423b4c` or later).
  A value Redmine does not have is left out with the chain kept connected; such an issue gets
  `jira-history.json`. New columns `migration_mapping_journals.history_journal_id` and `history_attachment_id`.
- verify: the last updated user is the latest journal's, the history ends in Jira's status (decision 41).
- Default author (decision 42): Jira content whose author has no Redmine account (issue author, comments,
  history, `jira-history.json`, attachment uploader) goes to the configured user, Redmine's Anonymous unless Jan
  picks another (`J2R_DEFAULT_AUTHOR_ID`); with the Extended API 09, 10 and 11 stop without it instead of writing
  as the API user.
- `tools/testenv/run_all.sh`: `STOP_AT=S1|S2|S3` ends a test run at that mark; `compare_requests.php
  --any-order` compares two recordings as a set.

### Changed
- Phase 6 (plan M1), no change of behaviour: the 16 scripts share their start, option parsing (each script's own
  rule kept, `src/Cli/ScriptCommandLines.php`), phases, configuration sections, database, clients and clock through
  `src/`; about 2,300 lines of copies are gone. All 16 are under PHPStan level 8 (one analysis per script, a baseline
  of 12 with reasons in `tools/phpstan-baseline/`) and PSR-12. Proven with the record proxy on 5.1 and 7.0 (every
  Redmine request the same), the opcodes of the layout commits and 752 recorded command lines.
- An existing Redmine (decisions 33 to 35): Jira priorities map onto existing Redmine priorities, the rest is a
  review row and 06 creates none by default; the roles the `workflows` step opens come from the configuration,
  and the step stops on a role it cannot use; in mode `project` a tracker Redmine already had keeps its global
  workflow, the union becomes the global workflow only of the trackers 07 created.
- Fewer traces of the migration's own writes (Jan, 2026-10-15): 10 sends the Jira labels as tags when it creates
  the issue (no journal); the journal of 10's second pass is no longer marked "Edited"; 13's update on 7.0 and the
  relink keep the issue's updated time.
- Issues are created with their description; parents and rewritten Jira links follow in the same
  run, the final update sends only what changed (B10, B11, B22).
- An open subtask under a closed parent: the parent gets the open status `Resolved` by default
  (`subtask_status_behavior = open_parent`); the Jira status stays in the mapping.
- Attachments keep their Jira name; `{jiraId}__` is added only when the issue has the name twice (B14).
- Mentions become `@login` (decision 4).
- Jira app accounts are IGNORED instead of left for manual review (B3).
- Custom fields are enabled where Jira issues hold values, not only where Jira shows the field (B24).
- 'blocks' relations to closed issues are created instead of held for review.
- Tracker names are shortened to Redmine's 30 characters (B1).
- 04: a user in a Jira project role becomes a member of that project; the role is no longer given to
  every group the user is in (B31). Ignored roles and app accounts end IGNORED.
- 16 exports workflows through `workflow/search` with transitions, rules and statuses.
- Custom fields: the values Jira issues hold are taken in the usage phase, once 10 staged the issues:
  app fields without allowed values (label fields) are migrated, and a select value Jira no longer
  offers is added to the field (B37). 10 reads the options from 08's Redmine snapshot.
- A Jira link between an issue and its own parent or subtask is IGNORED (B40).
- 10 cleans descriptions (avatars, profile links, Jira issue URLs) before it pushes them; 11 no longer
  rewrites issue descriptions (B41). Comments lose avatars too.
- Link markup that Jira showed as text becomes a link (B42).
- 04's push adds a role to an existing membership (PUT) instead of a second POST that Redmine
  refused (422); the `members` manual step only activates locked users.
- A link to a migrated issue with its own text reads "text (#123)"; a status lozenge becomes inline
  code; Outlook safe links in mailed comments become the link itself.

### Fixed
- Redmine's "Last updated by" is the author of the journal with the highest id: 11 pushed every comment before
  every changelog entry, so on 222 of 1,870 issues (7.0 test run) it was not the last person who acted in Jira.
  11 now pushes per issue in Jira's time order, the history in between (decision 41, option C).
- After 11 an issue showed the time of its last journal instead of Jira's updated time: each journal set it to
  its own time. Every journal now sends Jira's updated time of its issue (decision 41).
- 13 on 7.0 sent an issue's tags as one comma-joined string, which redmine_tags 7.0 stored as one tag (B75).
- The relink reported and counted texts without a Jira link, since its rewrite also tidied the spacing around
  references (B76); it now rewrites the links only.
- A float custom field lost the zeros of a whole number: 0 became empty, 10 became "1" (B79); verify now compares
  number fields by value.
- A label app entry with several comma-joined labels became one value, or held the issue back (B77); each label
  counts on its own.
- A checklist item longer than redmine_checklists allows stopped the issue's checklist there (B80); it is now cut to
  the allowed length and the rest of the checklist follows.
- 11 reported a failed read of an issue's comments or history as a warning without the issue's key (B81); it is now
  an error with the key, counted in the summary.
- `restore` could remove transitions Redmine had before the migration when the workflows step's list was left from
  another run (B82); the step now stops on such a list.
- A rerun of 15 did not try a failed checklist again (B83).
- The writes after an issue exists (10's second pass, 12, 13, the relink) leave no journal and keep the updated
  times: they send `skip_journal` through the Extended API, 8e7096c or later (Jan, decision 37).
- Jira's "Agent Sessions" field is not migrated (Jan, decision 40): `migration.custom_fields.not_migrated`.
- docs/PRODUCTION.md B9: staging is kept as an encrypted dump, read back before it is dropped from the server (Jan,
  decision 41: Jira's history follows after production from that dump).
- 06 put new priorities at position 1, 2, 3, below every existing priority (B73): a new priority now goes after
  the existing ones.
- Text conversion (decision 26, B60 to B70): ADF tables with merged cells and ADF code blocks; bold and
  italic that Jira splits or nests; the text "<pre>" in code blocks; inline code Jira showed as "{{...}}";
  MediaWiki source pasted into Jira becomes a code block with its exact text; a link rewrite no longer
  swallows the text before a profile link; typed link markup keeps its spacing and converts with brackets
  in its label; a space between a mention and a link; bare addresses without backslashes; a link inside a
  link; an issue reference no longer followed by a letter (Redmine linked none). The bench reports the old
  measure, the key rewrite left out, and the corrected measure.
- Central retry: 502 and 504 from a gateway are retried for GET and HEAD (B71).
- Cascading fields: value dependencies are set as option ids after the child field exists (B7).
- A due date before the creation date becomes the start date (B8).
- An unmapped assignee no longer blocks the issue (B9).
- Link rewrite only for created issues; failed and manual rows are judged again on the next run (B6).
- E-mail and URL autolinks survive the Markdown conversion (B15).
- Tags on Redmine 7.0: fall back to the issue update when `POST /issues/:id/tags.json` is gone (B19).
- Watchers: read back what Redmine stored instead of trusting the 204 (B20).
- No Jira projects found stops the run with the likely causes (F5).
- 16 with a read-scoped token: no legacy `/workflow`, `/issuetypescheme/{id}` or `/automation/rules`
  call (401); workflows keep their names; pages shorter than asked no longer skip items; issue type
  scheme projects are read from the nested answer (B25 to B28).
- Jira links returned on the gateway are not prefixed twice (04 read no project role, B30).
- 04 reruns no longer queue memberships it already created.
- **11 no longer pushes every journal again on a rerun**; failed and skipped journals are retried (B35).
- 12 retries a refused relation on the next run (its automation hash is written with the failure, B34).
- 09 retries a failed download once in the same pull and keeps the reason (B33); 10 no longer waits for
  an attachment that is switched off.
- 08 no longer deletes the cascading parent mappings on a rerun (B43).
- A mention or `#id` followed by a number keeps its id; "user 5" in text is no longer a mention (B44).
- A GET whose transfer was cut off (cURL 18) is retried like a failed connection.
- Heading anchors no longer turn a text into one HTML paragraph that Redmine shows as text.
- Verify and bench: the issue type icon of an issue macro is not counted as an image.
- An unknown phase in `--phases` or `--skip` stops a script; 09, 11 and 13 to 16 ran every phase instead. Phases
  run in the script's order (02 and 03 followed the order given).
- 08 calls the Extended API relative to the Redmine base URL, so a Redmine under a sub-path keeps it (F6).
- Attachments keep their Jira creation time: 09 dropped Jira's offset, so Redmine showed them one or two hours
  late (B58); verify compares the creation times.
- Every timestamp in UTC whatever the server's timezone: PHP's default timezone and the MySQL session.
- A status change lists only the attachments its items name, not every file uploaded within 5 minutes of it
  (B59); for comments that window is `migration.journals.comment_attachment_window` (seconds, 0 by default).
- 05 also reads Jira's status search (`GET /rest/api/3/statuses/search`) and adds the statuses the status
  list lacks when a staged project uses them (`GET /rest/api/3/statuses/{id}/projectUsages`); their
  transitions were dropped from the workflow proposal (B57).

## [0.0.1]

- Initial release
