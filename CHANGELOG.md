# Changelog

All notable changes to this project will be documented in this file.

## [0.0.1] - 2025-09-24

- Initial commit
- Add composer dependencies
- Add a first migration schema
- Add a README.md
- Add a configuration logic
- Retrieve users from Jira
- Retrieve users from Redmine
- Add CLI controls, docs, and changelog for the user migration script

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

## [0.0.4] - 2025-09-27

- Add `01_migrate_projects.php`, covering the full extract/transform/push workflow for Redmine projects (with dry-run previews and creation support).
- Promote project synchronisation to the first migration step and renumber the user and group scripts to `02_migrate_users.php` and `03_migrate_groups.php` respectively.
- Bump the CLI script versions to `0.0.4` and refresh the README to document the new entry point, updated command names, and workflow ordering.

## [0.0.5] - 2025-09-28

- Introduce `04_migrate_roles.php` to reconcile Jira project roles with Redmine projects, groups, and roles, including a manual
  push checklist for assigning groups to projects.
- Extend the staging schema with `staging_jira_project_role_actors`, enrich `migration_mapping_roles`, and add `migration_mapping_project_role_groups` to track per-project role memberships.
- Add `migration.roles.default_redmine_role_id` configuration support and expand the README with workflow guidance for the new script.
- Bump all CLI entry points to `0.0.5` and refresh the documentation to reflect the updated version and role-mapping capabilities.
- Capture Redmine group project role memberships in staging and automatically mark existing assignments as already recorded during the role transform.

## [0.0.6] - 2025-09-29

- Add `05_migrate_statuses.php` to extract Jira statuses, refresh the Redmine snapshot, reconcile mappings with
  automation-hash preservation, and print a manual creation checklist.
- Add `06_migrate_priorities.php` following the same phased workflow for issue priorities, including Jira/Redmine
  upserts, reconciliation, and a manual push summary.
- Extend the staging schema with richer metadata for status and priority mappings (names, proposed values, and
  automation hashes) so manual overrides persist across reruns.
- Bump all CLI entry points to `0.0.6` and document the new scripts and workflow guidance in the README.

## [0.0.7] - 2025-09-30

- Introduce `07_migrate_trackers.php` to reconcile Jira issue types with Redmine trackers, including optional
  creation support through the `redmine_extended_api` plugin.
- Extend the tracker mapping table with Jira metadata, proposed Redmine attributes, and automation hashes so manual
  overrides persist across reruns, and teach the transform phase to derive default status IDs automatically.
- Add `migration.trackers.default_redmine_status_id` to the configuration and document the new workflow in the README.
- Refresh the README examples to cover the tracker automation flow and bump all CLI entry points to `0.0.7`.

## [0.0.8] - 2025-10-01

- Add `08_migrate_custom_fields.php`, covering the full extract/transform/push pipeline for Jira custom fields with
  optional automation via the `redmine_extended_api` plugin.
- Enrich the staging and mapping schema for custom fields with metadata columns (schema hints, proposed Redmine
  attributes, automation hashes) so manual overrides persist across reruns and list-style fields can capture
  proposed option values.
- Teach the README about the new script, update the extended API guidance with custom field examples, and bump all
  CLI entry points to `0.0.8`.

## [0.0.9] - 2025-10-02

- Extend `08_migrate_custom_fields.php` to retrieve Jira field contexts and allowed option values, persisting them
  in the new `staging_jira_field_contexts` table for downstream analysis.
- Expand `migration_mapping_custom_fields` with context metadata (hashes, Jira project/issue type lists, allowed
  values) so the transform phase can reason about per-project option differences.
- Update the transform logic to split divergent Jira contexts into distinct Redmine proposals with context-aware
  names, automatically populate option lists, and map Jira projects/issue types to Redmine projects and trackers.
- Refresh the README with the context-aware workflow, clarify the extended API checklist, and bump all CLI entry
  points to version `0.0.9`.

## [0.0.10] - 2025-11-14

- Extend `08_migrate_custom_fields.php` with native support for cascading select
  fields by detecting the `redmine_depending_custom_fields` plugin, creating the
  required parent list via the extended API, and issuing dependent field
  requests against the plugin's REST endpoint.
- Capture Jira context option hierarchies (parent/child relationships) in
  `staging_jira_field_contexts` and propagate them through the transform so
  migrations can surface parent sets, child unions, and dependency maps.
- Persist Redmine parent custom field identifiers in the mapping table so
  repeated runs and manual acknowledgements honour previously created parent
  fields.
- Refresh the README with the new cascading workflow, document the plugin
  behaviour, and bump all CLI entry points to `0.0.10`.
