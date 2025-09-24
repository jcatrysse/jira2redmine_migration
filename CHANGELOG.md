# Changelog

All notable changes to this project will be documented in this file.

## [0.0.1] - 2025-09-24

- Initial commit
- Add composer dependecies
- Add a first migration schema
- Add a README.md
- Add a configuration logic
- Retrieve users from Jira
- Retrieve users from Redmine
- Add CLI controls, docs, and changelog for user migration script

## [0.0.2] - 2025-09-25

- Implement the transform/reconciliation phase of `01_migrate_users.php`.
- Extend the `migration_mapping_users` table with Jira metadata, proposed Redmine values, and a `match_type` column.
- Match Jira accounts to Redmine users by login/e-mail and derive default first/last names from Jira `display_name`.
- Document the new workflow, CLI options, and manual override process.
- Preserve manual overrides in `migration_mapping_users` with automation hashes, reporting skipped rows in the CLI summary.
- Treat blank or malformed `automation_hash` values as cleared so rerunning the transform re-analyses those rows instead of
  flagging false manual overrides.
- Add a `proposed_redmine_status` column defaulting to `LOCKED` so operators can choose whether new Redmine accounts start active or locked, and document the rerun behaviour.
- Respect the configurable `migration.users.default_redmine_user_status` toggle when preparing proposed Redmine accounts.
- Stop expanding Redmine group and membership data during the user snapshot to keep the import leaner for now.
