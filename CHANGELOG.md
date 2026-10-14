# Changelog

All notable changes to this project. Steps to take before and after a migration are in
[docs/RUNBOOK.md](docs/RUNBOOK.md); the findings (F, B) are in [docs/MIGRATION-PLAN.md](docs/MIGRATION-PLAN.md).

## [Unreleased] (branch redmine70-migration)

### Added
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

### Changed
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
- 05 also reads Jira's status search (`GET /rest/api/3/statuses/search`) and adds the statuses the status
  list lacks when a staged project uses them (`GET /rest/api/3/statuses/{id}/projectUsages`); their
  transitions were dropped from the workflow proposal (B57).

## [0.0.1]

- Initial release
