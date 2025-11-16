# Changelog

All notable changes to this project will be documented in this file.

## [TODO]

- Migrate trackers script: link trackers to projects in Redmine.
- Migrate custom fields script: add support for datetime fields in redmine using https://github.com/jcatrysse/redmine_datetime_custom_field and the https://github.com/jcatrysse/redmine_extended_api for API access.
- Migrate custom fields script: investigate unsupported field types (any, team, option, option-with-child, option2, sd-customerrequesttype, object, sd-approvals, ...)
- Migrate custom fields script: investigate cascading fields and how to match them with https://github.com/jcatrysse/redmine_depending_custom_fields
- Migrate custom fields script: investigate and validate the transformation from Jira Context to separate custom fields in Redmine.
- Fine-tune the attachments, issues, and journals scripts.
- Migrate issues script: on a rerun, newer issues should be fetched.
- Migrate issues script: on transform, the script should ignore custom fields we didn't create in Redmine, based on the migration_mapping_custom_fields table.
- Create the missing scripts.

## [0.0.27] - 2025-11-29

- Extend the custom field transform so it reads the latest usage snapshot,
  auto-ignores Jira custom fields that never appear in staged issues, and
  surfaces the usage statistics in the mapping notes to aid manual reviews.
- Surface an `Ignored (unused)` counter in the transform summary and bump the
  custom field CLI version to `0.0.12`.

## [0.0.26] - 2025-11-28

- Harden the custom field usage phase so it only analyses issues whose raw Jira
  payload is valid JSON, preventing the aggregation query from failing when
  staging data was ingested before the issue extraction script was fixed.

## [0.0.25] - 2025-11-27

- Fix `11_migrate_issues.php` so the Jira extraction phase calls
  `/rest/api/3/search/jql` via query parameters instead of the deprecated POST
  payload, reintroducing the `fieldsByKeys` flag and honouring Jira's reported
  batch size to prevent the `Invalid request payload` errors seen when
  requesting rendered HTML descriptions. The CLI now reports version `0.0.25`.

## [0.0.24] - 2025-11-26

- Capture Jira's rendered HTML descriptions and comment bodies in the staging
  tables, refresh the attachment metadata index, and depend on
  `league/html-to-markdown` so the issue and journal transforms can convert the
  HTML into CommonMark while rewriting Jira attachment links to Redmine's
  `attachment:` syntax.
- Document the new workflow in the README and bump the CLI versions so operators
  know the description/comment Markdown now honours the rendered HTML payloads
  and attachment metadata stored during extraction.

## [0.0.23] - 2025-11-25

- Teach `11_migrate_issues.php` to persist Jira issue link metadata in the new
  `staging_jira_issue_links` table so downstream scripts can reason about
  relation directionality, and relax `staging_jira_attachments.created_at` to
  accept `NULL` values so the association hint fallback works when Jira omits a
  timestamp. The CLI now reports version `0.0.23`.
- Introduce `13_migrate_subtasks.php` to analyse Jira parent/child pairs and
  update the corresponding Redmine `parent_issue_id` assignments with optional
  dry-run previews and automation-hash preservation.
- Add `14_migrate_issue_relations.php` plus the
  `migration_mapping_issue_relations` table to map Jira links to Redmine relation
  types, queue rows for review, and create the final relations via the Redmine
  REST API.
- Document the new workflow in the README, extend the script order table with
  the subtask/relation steps, and describe the new CLIs so operators know how to
  run them before the tags migration.

## [0.0.22] - 2025-11-24

- Roll back the Jira extractor to the `/rest/api/3/search/jql` endpoint and trim
  any user-supplied `ORDER BY` clause before the script appends its own
  deterministic `ORDER BY id ASC`, preventing the Atlassian 410 and duplicate
  sort errors during per-project pagination. The CLI now reports version
  `0.0.22`.
- Simplify the Jira search payload by sending only the `fields` array (Jira
  defaults the rest), avoiding the `Invalid request payload` responses caused by
  schema mismatches.
- Document the new version number and the automatic `ORDER BY` sanitiser in the
  README so operators know custom JQL snippets should focus on filters only.

## [0.0.21] - 2025-11-24

- Fix the Jira search payload built by `11_migrate_issues.php` so it passes an
  array for both `fields` and `expand`, plus an explicit `fieldsByKeys` flag,
  addressing the Atlassian `Invalid request payload` errors reported after the
  per-project pagination refactor. The CLI now reports version `0.0.21`.
- Update the README option table to reflect the new version number so operators
  can easily confirm they are running the patched build.

## [0.0.20] - 2025-11-24

- Rebuild `11_migrate_issues.php` so the Jira extraction phase iterates over
  every project recorded in `migration_mapping_projects`, querying
  `/rest/api/3/search` with `project = "KEY"` filters, keyset pagination, and an
  array-based `fields` payload before marking the new
  `issues_extracted_at` timestamp once a project finishes. The CLI now reports
  version `0.0.20`, and reruns automatically resume with the next incomplete
  project instead of relying on manual JQL scoping.
- Extend the schema with `migration_mapping_projects.issues_extracted_at`,
  document the resumable workflow (and upgrade SQL) in the README, and clarify
  that `migration.issues.jql` is AND-ed with the per-project constraint in
  `config/config.default.php`.

## [0.0.19] - 2025-11-23

- Fix `11_migrate_issues.php` so Jira extraction once again targets
  `/rest/api/3/search/jql`, sending the request payload Atlassian now
  expects (`fields` as an array) while preserving the `ORDER BY id ASC`
  keyset pagination strategy.
- Inject a default `id >= 0` constraint when no custom JQL is supplied so
  the initial search call always satisfies Jira's bounded query
  requirement, and bump the CLI version plus README references to
  `0.0.19`.

## [0.0.18] - 2025-11-22

- Rework `11_migrate_issues.php` so Jira extraction uses keyset pagination
  against `/rest/api/3/search`, automatically enforcing `ORDER BY id ASC` and
  appending `id > <last_seen_id>` filters to stream every issue without
  requiring per-project scoping. The CLI version now reports `0.0.18`.
- Drop the short-lived `migration.issues.project_keys` knob from the default
  configuration, document the new pagination behaviour in the README, and keep
  `migration.issues.jql` as the optional place to add custom filters when
  batching exports.

## [0.0.17] - 2025-11-21

- Enforce bounded Jira searches during issue extraction by teaching
  `11_migrate_issues.php` to require `migration.issues.project_keys` (or a fully
  scoped custom JQL) before calling `/rest/api/3/search/jql`, automatically
  constructing `project in (...) ORDER BY created ASC` queries for bulk project
  migrations.
- Extend `config/config.default.php` and the README with the new
  `migration.issues.project_keys` knob plus guidance on batching projects per
  run so large catalogues can be migrated safely.
- Bump the issue migration CLI version to `0.0.17` and update the documented
  version hints.

## [0.0.16] - 2025-11-20

- Update `11_migrate_issues.php` to call Jira's `/rest/api/3/search/jql` endpoint
  with next-page tokens so the attachments, issues, and journals pipelines no
  longer hit the retired search API.
- Audit the other CLI scripts to ensure no deprecated Jira search endpoints are
  referenced.

## [0.0.15] - 2025-11-19

- Split `10_migrate_attachments.php` into explicit `jira`, `pull`, `transform`,
  and `push` phases with independent confirmations, optional per-phase limits,
  and refreshed CLI help text.
- Allow operators to stage smaller batches by toggling the new
  `download_enabled` / `upload_enabled` flags on `migration_mapping_attachments`
  rows and storing attachment file sizes as `jira_filesize` for reporting.
- Update the README and database schema to document the new columns, workflow,
  and testing guidance, and bump the attachment script version to `0.0.15`.

## [0.0.14] - 2025-11-18

- Introduce `10_migrate_attachments.php` to synchronise attachment mappings,
  download binaries into the working directory, and pre-upload them to Redmine
  while labelling each file for issue or journal association.
- Update `11_migrate_issues.php` to consume pre-generated attachment tokens,
  classify association hints, and reconcile Redmine attachment identifiers after
  issue creation.
- Add `12_migrate_journals.php` to migrate Jira comments and changelog entries,
  converting ADF payloads to notes and reusing journal-scoped attachment tokens.
- Extend the schema with attachment association metadata (`redmine_issue_id`,
  `association_hint`), refresh the README with the new workflows, and bump script
  versions to `0.0.14`.

## [0.0.13] - 2025-11-17

- Teach `11_migrate_issues.php` to download Jira attachments into
  `tmp/attachments/jira`, maintain the `migration_mapping_attachments` workflow,
  and upload binaries to Redmine ahead of issue creation while capturing upload
  tokens for later association.
- Extend the push phase reporting so operators can see pending attachment
  counts during dry runs and successful uploads when `--confirm-push` is used.
- Refresh the README with the new attachment pipeline details, the updated
  script version, and guidance on ensuring the temporary directory is writable.

## [0.0.12] - 2025-11-16

- Streamline the issue migration by dropping the Redmine issue snapshot phase
  and table, keeping the staging schema focused on the Jira catalogue while the
  mapping table records successful Redmine creations.
- Update `11_migrate_issues.php` to run only the Jira, transform, and push
  phases, bump its CLI version, and document the leaner workflow in the README.

## [0.0.11] - 2025-11-15

- Add `11_migrate_issues.php`, completing the issue ETL workflow with Jira
  extraction (including attachment and label snapshots), a Redmine issue
  snapshot, dependency-aware transforms, and an optional push phase that posts
  to `POST /issues.json` after explicit confirmation.
- Extend the staging schema with richer issue metadata, add the
  `staging_redmine_issues` table, and expand `migration_mapping_issues` so the
  transform phase can propose Redmine project/tracker/status/priority/user
  identifiers while preserving manual overrides via automation hashes.
- Introduce a configurable `migration.issues` section (Jira batch size and
  fallback Redmine IDs) and capture Jira attachments/labels alongside issues so
  downstream steps (custom field usage, attachments, tags) operate on fresh
  staging data.
- Refresh the README with the new issue migration workflow and update all CLI
  entry points to version `0.0.11`.

## [0.0.10] - 2025-11-14

- Extend `08_migrate_custom_fields.php` with native support for cascading select
  fields by detecting the `redmine_depending_custom_fields` plugin, creating the
  required parent list via the extended API, and issuing dependent field
  requests against the plugin's REST endpoint.
- Capture Jira context option hierarchies (parent/child relationships) in
  `staging_jira_field_contexts` and propagate them through the transform so
  migrations can surface parent sets, child unions, and dependency maps.
- Persist Redmine parent custom field identifiers in the mapping table, so
  repeated runs and manual acknowledgements honour previously created parent
  fields.
- Refresh the README with the new cascading workflow, document the plugin
  behaviour, and bump all CLI entry points to `0.0.10`.

## [0.0.9] - 2025-10-02

- Extend `08_migrate_custom_fields.php` to retrieve Jira field contexts and allowed option values, persisting them
  in the new `staging_jira_field_contexts` table for downstream analysis.
- Expand `migration_mapping_custom_fields` with context metadata (hashes, Jira project/issue type lists, allowed
  values) so the transform phase can reason about per-project option differences.
- Update the transform logic to split divergent Jira contexts into distinct Redmine proposals with context-aware
  names, automatically populate option lists, and map Jira projects/issue types to Redmine projects and trackers.
- Refresh the README with the context-aware workflow, clarify the extended API checklist, and bump all CLI entry
  points to version `0.0.9`.

## [0.0.8] - 2025-10-01

- Add `08_migrate_custom_fields.php`, covering the full extract/transform/push pipeline for Jira custom fields with
  optional automation via the `redmine_extended_api` plugin.
- Enrich the staging and mapping schema for custom fields with metadata columns (schema hints, proposed Redmine
  attributes, automation hashes) so manual overrides persist across reruns and list-style fields can capture
  proposed option values.
- Teach the README about the new script, update the extended API guidance with custom field examples, and bump all
  CLI entry points to `0.0.8`.

## [0.0.7] - 2025-09-30

- Introduce `07_migrate_trackers.php` to reconcile Jira issue types with Redmine trackers, including optional
  creation support through the `redmine_extended_api` plugin.
- Extend the tracker mapping table with Jira metadata, proposed Redmine attributes, and automation hashes so manual
  overrides persist across reruns, and teach the transform phase to derive default status IDs automatically.
- Add `migration.trackers.default_redmine_status_id` to the configuration and document the new workflow in the README.
- Refresh the README examples to cover the tracker automation flow and bump all CLI entry points to `0.0.7`.

## [0.0.6] - 2025-09-29

- Add `05_migrate_statuses.php` to extract Jira statuses, refresh the Redmine snapshot, reconcile mappings with
  automation-hash preservation, and print a manual creation checklist.
- Add `06_migrate_priorities.php` following the same phased workflow for issue priorities, including Jira/Redmine
  upserts, reconciliation, and a manual push summary.
- Extend the staging schema with richer metadata for status and priority mappings (names, proposed values, and
  automation hashes) so manual overrides persist across reruns.
- Bump all CLI entry points to `0.0.6` and document the new scripts and workflow guidance in the README.

## [0.0.5] - 2025-09-28

- Introduce `04_migrate_roles.php` to reconcile Jira project roles with Redmine projects, groups, and roles, including a manual
  push checklist for assigning groups to projects.
- Extend the staging schema with `staging_jira_project_role_actors`, enrich `migration_mapping_roles`, and add `migration_mapping_project_role_groups` to track per-project role memberships.
- Add `migration.roles.default_redmine_role_id` configuration support and expand the README with workflow guidance for the new script.
- Bump all CLI entry points to `0.0.5` and refresh the documentation to reflect the updated version and role-mapping capabilities.
- Capture Redmine group project role memberships in staging and automatically mark existing assignments as already recorded during the role transform.

## [0.0.4] - 2025-09-27

- Add `01_migrate_projects.php`, covering the full extract/transform/push workflow for Redmine projects (with dry-run previews and creation support).
- Promote project synchronisation to the first migration step and renumber the user and group scripts to `02_migrate_users.php` and `03_migrate_groups.php` respectively.
- Bump the CLI script versions to `0.0.4` and refresh the README to document the new entry point, updated command names, and workflow ordering.

## [0.0.3] - 2025-09-26

- Extend `02_migrate_groups.php` to ingest Jira and Redmine group memberships, persisting them in the new `staging_*_group_members`
  tables and the `migration_mapping_group_members` state machine.
- Rework the transform phase so it reconciles both group metadata and memberships, introducing `AWAITING_GROUP`, `AWAITING_USER`,
  and `READY_FOR_ASSIGNMENT` statuses while continuing to respect automation hashes.
- Teach the push phase to assign missing Redmine users to existing or newly created groups (with dry-run previews) before
  updating the mapping tables.
- Streamline the membership staging tables to store only identifiers and raw payloads, deriving names from the user/group
  snapshots while keeping the Jira group name in the membership mapping for operator context.
- Update the staging schema, README, and CLI documentation to reflect the membership workflow and the new recommended
  migration order where project synchronisation (`00_sync_projects.php`) leads the pipeline.
- Ensure the membership transform re-evaluates groups once a Redmine identifier is present so rows no longer stall in
  `AWAITING_GROUP` after successful creations.
- Reset membership automation hashes when group identifiers are backfilled from the mapping table, so subsequent transforms
  treat them as automation-managed rows instead of reporting spurious manual overrides.
- Allow `01_migrate_users.php` to read `migration.users.auth_source_id` so newly created Redmine accounts can be bound to the correct authentication mode (e.g. LDAP).
- Include the optional `auth_source_id` in push requests when configured and document the new toggle with refreshed sample configuration.

## [0.0.2] - 2025-09-25

- Implement the transform/reconciliation phase of `users.php`.
- Extend the `migration_mapping_users` table with Jira metadata, proposed Redmine values, and a `match_type` column.
- Match Jira accounts to Redmine users by login/e-mail and derive default first/last names from Jira `display_name`.
- Document the new workflow, CLI options, and manual override process.
- Preserve manual overrides in `migration_mapping_users` with automation hashes, reporting skipped rows in the CLI summary.
- Treat blank or malformed `automation_hash` values as cleared so rerunning the transform re-analyses those rows instead of
  flagging false manual overrides.
- Add a `proposed_redmine_status` column defaulting to `LOCKED` so operators can choose whether new Redmine accounts start active or locked, and document the rerun behaviour.
- Respect the configurable `migration.users.default_redmine_user_status` toggle when preparing proposed Redmine accounts.
- Stop expanding Redmine group and membership data during the user snapshot to keep the import leaner for now.
- Implement the push phase for `users`

## [0.0.1] - 2025-09-24

- Initial commit
- Add composer dependencies
- Add a first migration schema
- Add a README.md
- Add a configuration logic
- Retrieve users from Jira
- Retrieve users from Redmine
- Add CLI controls, docs, and changelog for the user migration script
