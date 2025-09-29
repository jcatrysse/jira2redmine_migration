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
- Reset membership automation hashes when group identifiers are backfilled from the mapping table so subsequent transforms
  treat them as automation-managed rows instead of reporting spurious manual overrides.
- Allow `01_migrate_users.php` to read `migration.users.auth_source_id` so newly created Redmine accounts can be bound to the correct authentication mode (e.g. LDAP).
- Include the optional `auth_source_id` in push requests when configured and document the new toggle with refreshed sample configuration.

## [0.0.4] - 2025-09-27

- Add `01_migrate_projects.php`, covering the full extract/transform/push workflow for Redmine projects (with dry-run previews and creation support).
- Promote project synchronisation to the first migration step and renumber the user and group scripts to `02_migrate_users.php` and `03_migrate_groups.php` respectively.
- Bump the CLI script versions to `0.0.4` and refresh the README to document the new entry point, updated command names, and workflow ordering.

## [0.0.5] - 2025-09-28

- Introduce `04_migrate_roles.php` to reconcile Jira project roles with Redmine projects, groups, and roles, including a manual push checklist for assigning groups to projects.
- Extend the staging schema with `staging_jira_project_role_actors`, enrich `migration_mapping_roles`, and add `migration_mapping_project_role_groups` to track per-project role memberships.
- Add `migration.roles.default_redmine_role_id` configuration support and expand the README with workflow guidance for the new script.
- Bump all CLI entry points to `0.0.5` and refresh the documentation to reflect the updated version and role-mapping capabilities.
- Capture Redmine group project role memberships in staging and automatically mark existing assignments as already recorded during the role transform.

