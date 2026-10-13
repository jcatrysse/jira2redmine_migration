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
- `tools/testenv/run_all.sh`: `STABILITY=1` reruns 08 to 15 and compares Redmine's counts.

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

## [0.0.1]

- Initial release
