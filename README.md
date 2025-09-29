# Jira to Redmine Migration: A Developer's Guide

## 1. Introduction

This document outlines the architecture and step-by-step process for migrating data from a Jira instance to a Redmine instance. The process is designed to be robust, auditable, and restartable, leveraging a MySQL staging database as an intermediary.

The core strategy is a phased **ETL (Extract, Transform, Load)** process, broken down into a series of scripts (e.g. PHP scripts), each responsible for migrating a specific type of data object (like users, projects, or issues).

### Core Principles

*   **Staging Database**: All data is first extracted into a local MySQL database. This acts as the single source of truth for the migration, preventing repeated API calls to Jira and allowing the process to be paused and resumed.
*   **Idempotency**: Each script is designed to be safely re-run multiple times. The system keeps track of what has already been migrated, preventing the creation of duplicate entries in Redmine.
*   **Dependency Management**: The migration is performed in a strict order to ensure that dependent objects exist before they are referenced. For example, users and projects must be migrated before the issues that belong to them.
*   **Mapping Tables**: For each object type, a `migration_mapping_*` table is used. This is the "brain" of the operation. It tracks the migration status of each item (e.g., `PENDING`, `SUCCESS`, `FAILED`) and, most importantly, stores the mapping between the old Jira ID and the new Redmine ID.

---

## 2. Prerequisites

*   **Environment**: A server with PHP, Composer (for managing dependencies like a Guzzle HTTP client), and a MySQL database.
*   **Jira Access**: A Jira account with enough permissions to read all the data you intend to migrate. An API token is required for authentication.
*   **Redmine Access**: A Redmine administrator account. An API key with administrative privileges is required to create new objects.
*   **Enable REST API**: Ensure that the REST API is enabled in your Redmine instance under `Administration -> Settings -> API`.

---

## 3. The Migration Process: Step-by-Step

The migration must be executed in the following order to respect data dependencies. Each step should be a separate script.

| Order | Script Name (Example)          | Object              | Purpose & Dependencies                                                                                                                                    |
|-------|--------------------------------|---------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------|
| 1     | `01_migrate_projects.php`      | Projects            | Extracts Jira projects, snapshots existing Redmine projects, and manages the mapping table. Establishes project identifiers before dependent scripts run. |
| 2     | `02_migrate_users.php`         | Users               | Creates users in Redmine that exist in Jira. Matched by email address. **Must run before groups or project memberships.**                                 |
| 3     | `03_migrate_groups.php`        | Groups & Members    | Creates user groups **and** synchronises group memberships. Depends on the user and project snapshots so memberships resolve correctly.                   |
| 4     | `04_migrate_roles.php`         | Project Roles       | Maps Jira's "Project Roles" to Redmine's "Groups".                                                                                                        |
| 5     | `05_migrate_statuses.php`      | Issue Statuses      | Ensures all necessary issue statuses exist in Redmine.                                                                                                    |
| 6     | `06_migrate_priorities.php`    | Issue Priorities    | Ensures all necessary issue priorities exist in Redmine.                                                                                                  |
| 7     | `07_migrate_trackers.php`      | Trackers            | Migrates Jira "Issue Types" to Redmine "Trackers".                                                                                                        |
| 8     | `08_migrate_custom_fields.php` | Custom Fields       | Migrates custom fields. This can be complex and may require manual mapping decisions.                                                                     |
| 9     | `09_assign_members.php`        | Project Memberships | Assigns migrated users and groups to migrated projects with the appropriate roles. **Depends on Users, Groups, Roles, Projects.**                         |
| 10    | `10_migrate_tags.php`          | Tags (Labels)       | Extracts all unique labels from Jira issues and creates them as tags in Redmine. **Depends on Issues (Extract phase).**                                   |
| 11    | `11_migrate_issues.php`        | **Issues**          | The main event. Migrates all issues, using the mapping tables to link to the correct Redmine project, tracker, user, status, etc.                         |
| 12    | `12_migrate_journals.php`      | Comments & History  | Migrates issue comments and changelogs. **Depends on Issues.**                                                                                            |
| 13    | `13_migrate_attachments.php`   | Attachments         | Downloads attachments from Jira and uploads them to the corresponding Redmine issues. **Depends on Issues.**                                              |

---

## 4. How Each Script Works: The ETL Pattern

Every migration script should follow the same three-phase logic.

### Phase 1: Extract

*   **From Jira**: Connect to the Jira v3 REST API. Fetch all relevant objects (e.g. all projects). Handle pagination to ensure you get all records. For each object, insert its raw data into the corresponding `staging_jira_*` table. Use an `INSERT... ON DUPLICATE KEY UPDATE` statement to avoid errors on re-runs.
*   **From Redmine**: Connect to the Redmine REST API. Fetch all existing objects of the same type (e.g. all current projects). Clear the `staging_redmine_*` table (`TRUNCATE`) and populate it with the fresh data. This ensures you are always comparing against the latest state of the target system.

### Phase 2: Transform & Reconcile

This is the core logic phase where no API calls are made to create data.

1.  **Populate Mapping Table**: Select all records from the `staging_jira_*` table and insert their primary IDs into the `migration_mapping_*` table with a default status of `PENDING_ANALYSIS`, ignoring duplicates.
2.  **Find Matches**: Join the `migration_mapping_*` table with the `staging_jira_*` and `staging_redmine_*` tables on a logical key (e.g., `user.email`, `project.key`, `group.name`).
    *   If a match is found, `UPDATE` the mapping record's status to `MATCH_FOUND` and store the existing Redmine ID.
3.  **Identify New Items**: For all remaining records in `PENDING_ANALYSIS` status, `UPDATE` their status to `READY_FOR_CREATION`.
4.  **Handle Transformations**: This is where you apply any business logic. For example, you might need to transform Jira's ADF (Atlassian Document Format) for descriptions into Markdown for Redmine or decide on a default password policy for new users.

#### User-specific reconciliation logic (implemented in `02_migrate_users.php`)

The second phase of the user migration script now drives the `migration_mapping_users` table. The workflow is fully database-driven, so you can review and amend data before the load phase:

1.  **Synchronise staging data** – every Jira account in `staging_jira_users` is inserted (or refreshed) in `migration_mapping_users`, together with its latest `jira_display_name` and `jira_email_address`.
2.  **Store transformation targets** – the mapping table now exposes editable columns (`proposed_redmine_login`, `proposed_redmine_mail`, `proposed_firstname`, `proposed_lastname`, `proposed_redmine_status`) plus a `match_type` flag (`NONE`, `LOGIN`, `MAIL`, `MANUAL`). Newly inserted rows default the proposed status according to the `default_redmine_user_status` setting (defaults to `LOCKED`) so operators can vet each account before creation; flip it to `ACTIVE` once the user should be provisioned immediately.
3.  **Automatic matching** – records are matched by Jira e-mail address, first against the Redmine login, then against the Redmine e-mail. Successful matches are marked `MATCH_FOUND`, the associated `redmine_user_id` is stored, and the proposed columns are populated with the current Redmine data.
4.  **Name derivation** – when no Redmine match exists, the script derives a first and last name from Jira's `display_name`. It first tries to split on the first comma (`"Last, First"`), otherwise it falls back to the first and last whitespace-separated tokens. If a usable pair cannot be inferred, the row is flagged for manual intervention.
5.  **Manual review hooks** – ambiguous matches, missing staging rows, or missing e-mail addresses set the status to `MANUAL_INTERVENTION_REQUIRED` with an explanatory note. Because the proposed columns live in the mapping table, you can correct them in SQL, change the `migration_status`, and re-run the transform.
6.  **Preserving operator tweaks** – each automated update persists an `automation_hash` signature. When you re-run the transform, the script compares the stored signature with the current column values. If you've edited the row manually, the hashes no longer match and the script logs a `[preserved]` message while leaving your changes intact. To re-enable automated reconciliation for that row, clear `automation_hash` (set it to `NULL` or an empty string) or set `match_type` to `MANUAL` to opt out permanently.
7.  **Status hygiene** – once a row is ready (`MATCH_FOUND` or `READY_FOR_CREATION`), any previous diagnostic note is cleared automatically to avoid stale messaging.

This structure is intended to be reused by later migration scripts so that manual verification happens in a single, consistent place.

### Phase 3: Load

1.  **Select Candidates**: `SELECT` all records from the `migration_mapping_*` table `WHERE status = 'READY_FOR_CREATION'`.
2.  **Loop and Create**: Iterate through the selected records. For each record:
    *   Construct the JSON/XML payload required by the Redmine API.
    *   Make a `POST` request to the appropriate Redmine API endpoint to create the object.
    *   **On Success (e.g. HTTP 201 Created)**: Parse the response to get the new Redmine ID. `UPDATE` the mapping record with the new ID and set the status to `CREATION_SUCCESS`.
    *   **On Failure (e.g. HTTP 422 Unprocessable Entity)**: `UPDATE` the mapping record's status to `CREATION_FAILED` and store the error message from the API response in the `notes` column for later debugging.

> **Current status:** `02_migrate_users.php` now implements the push phase end-to-end. Use `--dry-run` to review the queued creations and add `--confirm-push` once you are ready to create the accounts in Redmine.

---

## 5. Running `01_migrate_projects.php`

Version `0.0.5` introduces the dedicated entry point for synchronising projects. It follows the same CLI ergonomics as the other migration scripts, so you can iterate on the extract, transform, and push phases independently.

```bash
php 01_migrate_projects.php --help
```

### Available options

| Option            | Description                                                                           |
|-------------------|---------------------------------------------------------------------------------------|
| `-h`, `--help`    | Print usage information and exit.                                                     |
| `-V`, `--version` | Display the script version (`0.0.5`).                                                 |
| `--phases=<list>` | Comma-separated list of phases to run (e.g., `jira`, `redmine`, `transform`, `push`). |
| `--skip=<list>`   | Comma-separated list of phases to skip.                                               |
| `--confirm-push`  | Required toggle to allow the push phase to create projects in Redmine.                |
| `--dry-run`       | Print a detailed preview of the push phase without performing any API calls.          |

**Default behaviour:** when no phase-related options are supplied, all four phases (`jira`, `redmine`, `transform`, and `push`) are considered. The push phase only contacts Redmine when you provide `--confirm-push`; add `--dry-run` to inspect the queued creations without making any changes.

### Workflow highlights

1. **Jira extraction (`jira`)** – paginates through `/rest/api/3/project/search`, stores raw payloads, and refreshes the `staging_jira_projects` table with upserts so reruns remain idempotent.
2. **Redmine snapshot (`redmine`)** – truncates and rebuilds `staging_redmine_projects` from `GET /projects.json`, capturing identifiers, visibility, and parent relationships.
3. **Transform (`transform`)** – synchronises `migration_mapping_projects`, matching Jira project keys to Redmine identifiers (case-insensitive). Rows without matches are flagged `READY_FOR_CREATION`; missing keys or invalid identifiers are routed to `MANUAL_INTERVENTION_REQUIRED` with diagnostic notes.
4. **Push (`push`)** – previews queued project creations during dry runs and, once `--confirm-push` is supplied, posts to `POST /projects.json`. Successful creations are recorded as `CREATION_SUCCESS`, failures capture the Redmine error message in the mapping table for follow-up.

```bash
# Refresh both staging tables without pushing
php 01_migrate_projects.php --phases=jira,redmine

# Re-run the transform phase only
php 01_migrate_projects.php --phases=transform

# Preview the Redmine payloads without making changes
php 01_migrate_projects.php --phases=push --dry-run

# Perform the push after reviewing the dry-run output
php 01_migrate_projects.php --phases=push --confirm-push
```

---

## 6. Running `02_migrate_users.php`

The user migration entry point ships with a few quality-of-life CLI options that make it easier to iterate on specific phases without touching the rest of the workflow.

```bash
php 02_migrate_users.php --help
```

### Available options

| Option            | Description                                                                           |
|-------------------|---------------------------------------------------------------------------------------|
| `-h`, `--help`    | Print usage information and exit.                                                     |
| `-V`, `--version` | Display the script version (`0.0.5`).                                                 |
| `--phases=<list>` | Comma-separated list of phases to run (e.g., `jira`, `redmine`, `transform`, `push`). |
| `--skip=<list>`   | Comma-separated list of phases to skip.                                               |
| `--confirm-push`  | Required toggle to allow the push phase to create users in Redmine.                   |
| `--dry-run`       | Print a detailed preview of the push phase without performing any API calls.          |

**Default behaviour:** when no phase-related options are supplied, all four phases (`jira`, `redmine`, `transform`, and `push`) are considered. The push phase only contacts Redmine when you provide `--confirm-push`; add `--dry-run` to inspect the queued creations without making any changes.

### Usage examples

```bash
# Run all phases (default)
php 02_migrate_users.php

# Only refresh the Redmine snapshot
php 02_migrate_users.php --phases=redmine

# Re-run the Jira extraction while skipping the Redmine snapshot
php 02_migrate_users.php --skip=redmine

# Recalculate the mapping and proposed values without hitting any APIs
php 02_migrate_users.php --phases=transform

# Inspect the pending Redmine creations without making any changes
php 02_migrate_users.php --phases=push --dry-run

# Check the script version
php 02_migrate_users.php --version
```

### Configuring default Redmine user status

The transform phase honours the `migration.users.default_redmine_user_status` setting from your `config/config.local.php`. The
sample configuration ships with `LOCKED` so newly proposed accounts remain inactive until they are reviewed; switch it to
`ACTIVE` if your migration should provision users ready-to-go as soon as they are created.

### Assigning Redmine authentication sources

If your Redmine instance uses LDAP (or another authentication mode) for the accounts created by the migration, provide the
corresponding authentication mode identifier via `migration.users.auth_source_id` in `config/config.local.php`. Leave it unset or
`null` to fall back to Redmine's built-in password authentication. When present, the push phase adds the `auth_source_id` field to
the `POST /users.json` payload so the new accounts immediately reference the correct source.

---

## 7. Running `03_migrate_groups.php`

Version `0.0.5` keeps the companion entry point for synchronising Jira groups with Redmine aligned with the user and project scripts. The CLI surface remains identical, so your muscle memory continues to work:

```bash
php 03_migrate_groups.php --help
```

### Available options

| Option            | Description                                                                           |
|-------------------|---------------------------------------------------------------------------------------|
| `-h`, `--help`    | Print usage information and exit.                                                     |
| `-V`, `--version` | Display the script version (`0.0.5`).                                                 |
| `--phases=<list>` | Comma-separated list of phases to run (e.g., `jira`, `redmine`, `transform`, `push`). |
| `--skip=<list>`   | Comma-separated list of phases to skip.                                               |
| `--confirm-push`  | Required toggle to allow the push phase to create groups in Redmine.                  |
| `--dry-run`       | Print a detailed preview of the push phase without performing any API calls.          |

The default behaviour matches the user script: every phase runs unless you explicitly restrict or skip it, and push operations
are only attempted when you supply `--confirm-push`. Combine it with `--dry-run` for a safe preview of the queued creations.

### Workflow highlights

1. **Jira extraction (`jira`)** – retrieves groups via the `/rest/api/3/group/bulk` endpoint and their members via
   `/rest/api/3/group/member`. Raw payloads land in `staging_jira_groups` and the new `staging_jira_group_members`
   tables.
2. **Redmine snapshot (`redmine`)** – refreshes both `staging_redmine_groups` and `staging_redmine_group_members` by
   paginating through `GET /groups.json` (for metadata) and `GET /groups/<id>.json?include=users` (for membership).
3. **Transform (`transform`)** – synchronises `migration_mapping_groups` with Jira metadata (`jira_group_id` and
   `jira_group_name`), derives/updates `proposed_redmine_name`, and hashes the automated state so manual overrides are
   preserved. Matching is case-insensitive on the normalised group name:
   * A single Redmine match sets the status to `MATCH_FOUND` and records the existing group ID.
   * No match marks the row as `READY_FOR_CREATION` with the Jira name as the proposed Redmine label.
   * Ambiguities (duplicate names, missing staging data, or blank names) are routed to
     `MANUAL_INTERVENTION_REQUIRED` with an explanatory note and without overwriting operator edits.

   The transform also populates the new `migration_mapping_group_members` table so that memberships follow the same
   review workflow. Each member is classified into one of the following states:

   * `MATCH_FOUND` – the Redmine group already contains the user.
   * `READY_FOR_ASSIGNMENT` – the Redmine user exists but is not yet in the target group.
   * `AWAITING_GROUP` / `AWAITING_USER` – dependencies are still missing (e.g. the group must be created or the user
     has not been provisioned in Redmine yet).
   * `MANUAL_INTERVENTION_REQUIRED` – conflicting or incomplete data prevented an automatic decision.

   The staging tables now keep group memberships lean: `staging_jira_group_members` and
   `staging_redmine_group_members` only persist the foreign keys and raw payloads. Human-readable information is
   resolved on demand from `staging_jira_groups`, `staging_jira_users`, and `staging_redmine_users`, while
   `migration_mapping_group_members` retains the Jira group name alongside the Jira account ID so operators can still
   see which relationship is being handled during previews.

   Automation hashes now protect both the group metadata and the membership mappings, so manual tweaks persist across
   re-runs until you explicitly clear `automation_hash`.
4. **Push (`push`)** – iterates over rows flagged as `READY_FOR_CREATION`, previews them when `--dry-run` is supplied,
   and posts to `POST /groups.json` once `--confirm-push` is present. After group creation, the same phase now scans the
   membership mappings, posting `POST /groups/<id>/users.json` calls for every row in
   `READY_FOR_ASSIGNMENT`. Successes transition to `CREATION_SUCCESS` or `ASSIGNMENT_SUCCESS`; errors are written back as
   `CREATION_FAILED` or `ASSIGNMENT_FAILED` with the Redmine response message for later review.

> **Tip:** When a group is created for the first time, re-run the transform phase afterwards so memberships stuck in
> `AWAITING_GROUP` can automatically progress to `READY_FOR_ASSIGNMENT`. The sync step now clears each membership's
> automation hash when it backfills the new Redmine group identifier, so those rows are analysed again instead of being
> mistaken for manual overrides.

The groups and group membership mapping tables now mirror the user experience: update `proposed_redmine_name`, clear
`automation_hash`, tweak the membership status, or adjust `redmine_user_id` to control how re-runs treat individual rows.

---

## 8. Running `04_migrate_roles.php`

Jira's project roles sit between raw group membership and project visibility: each project can attach different Jira
groups (or individual users) to the same named role, while Redmine expects you to associate groups with projects and
assign an explicit Redmine role at the same time. The `04_migrate_roles.php` script bridges that gap by extracting the
Jira role definitions, enumerating every project/role/group combination, and turning them into actionable Redmine
assignments that build on the existing project and group migration state.

```bash
php 04_migrate_roles.php --help
```

### Available options

| Option            | Description                                                                           |
|-------------------|---------------------------------------------------------------------------------------|
| `-h`, `--help`    | Print usage information and exit.                                                     |
| `-V`, `--version` | Display the script version (`0.0.5`).                                                 |
| `--phases=<list>` | Comma-separated list of phases to run (e.g., `jira`, `redmine`, `transform`, `push`). |
| `--skip=<list>`   | Comma-separated list of phases to skip.                                               |
| `--confirm-push`  | Marks assignments as recorded after you manually action them in Redmine.              |
| `--dry-run`       | Preview the push output without updating the mapping table.                           |

### Workflow highlights

1. **Jira extraction (`jira`)** – fetches the global role catalogue from `/rest/api/3/role`, then walks every
   project to capture the actors attached to each role. Group actors populate the new
   `staging_jira_project_role_actors` table so each (project, role, group) combination becomes a first-class record.
2. **Redmine snapshot (`redmine`)** – refreshes `staging_redmine_roles` via `GET /roles.json`, giving the transform
   phase an authoritative list of available Redmine roles.
3. **Transform (`transform`)** – synchronises two mapping tables:
   * `migration_mapping_roles` automatically matches Jira role names against Redmine roles (case-insensitive). When a
     single match is found the mapping moves to `MATCH_FOUND`; ambiguous or missing matches are routed to
     `MANUAL_INTERVENTION_REQUIRED` so you can pick the target role manually. The automation hash protects any manual
     edits, mirroring the behaviour of the user and group scripts.
   * `migration_mapping_project_role_groups` reconciles every project/role/group tuple against the existing project
     and group migrations. Rows wait in `AWAITING_PROJECT` or `AWAITING_GROUP` until those dependencies are ready, and
     `AWAITING_ROLE` when no Redmine role can be inferred. Once all prerequisites resolve, the row is marked
     `READY_FOR_ASSIGNMENT` with the concrete Redmine project, group, and role identifiers.
4. **Push (`push`)** – there is no Redmine API call yet. Instead, the phase prints a human-friendly checklist detailing
   which Redmine project each group should be assigned to and with which role. Running with `--confirm-push`
   acknowledges that you've copied those instructions into Redmine by flipping the status to
   `ASSIGNMENT_RECORDED`; skipping `--confirm-push` leaves the rows untouched so you can revisit the checklist later.

> **Configuration tip:** set `migration.roles.default_redmine_role_id` in `config/config.local.php` when most
> assignments should share the same Redmine role. The transform phase proposes that role for unresolved mappings while
> still flagging the row as `AWAITING_ROLE`, making it obvious where a manual review is needed.
