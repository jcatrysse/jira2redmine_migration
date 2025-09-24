# Jira to Redmine Migration: A Developer's Guide

## 1. Introduction

This document outlines the architecture and step-by-step process for migrating data from a Jira instance to a Redmine instance. The process is designed to be robust, auditable, and restartable, leveraging a MySQL staging database as an intermediary.

The core strategy is a phased **ETL (Extract, Transform, Load)** process, broken down into a series of scripts (e.g., PHP scripts), each responsible for migrating a specific type of data object (like users, projects, or issues).

### Core Principles

*   **Staging Database**: All data is first extracted into a local MySQL database. This acts as the single source of truth for the migration, preventing repeated API calls to Jira and allowing the process to be paused and resumed.
*   **Idempotency**: Each script is designed to be safely re-run multiple times. The system keeps track of what has already been migrated, preventing the creation of duplicate entries in Redmine.
*   **Dependency Management**: The migration is performed in a strict order to ensure that dependent objects exist before they are referenced. For example, users and projects must be migrated before the issues that belong to them.
*   **Mapping Tables**: For each object type, a `migration_mapping_*` table is used. This is the "brain" of the operation. It tracks the migration status of each individual item (e.g., `PENDING`, `SUCCESS`, `FAILED`) and, most importantly, stores the mapping between the old Jira ID and the new Redmine ID.

---

## 2. Prerequisites

*   **Environment**: A server with PHP, Composer (for managing dependencies like a Guzzle HTTP client), and a MySQL database.
*   **Jira Access**: A Jira account with sufficient permissions to read all the data you intend to migrate. An API token is required for authentication.
*   **Redmine Access**: A Redmine administrator account. An API key with administrative privileges is required to create new objects.
*   **Enable REST API**: Ensure that the REST API is enabled in your Redmine instance under `Administration -> Settings -> API`.

---

## 3. The Migration Process: Step-by-Step

The migration must be executed in the following order to respect data dependencies. Each step should be a separate script.

| Order | Script Name (Example) | Object | Purpose & Dependencies |
|---|---|---|---|
| 1 | `01_migrate_users.php` | Users | Creates users in Redmine that exist in Jira. Matched by email address. **Must run first.** |
| 2 | `02_migrate_groups.php` | Groups | Creates user groups. |
| 3 | `03_migrate_roles.php` | Roles | Maps Jira's "Project Roles" to Redmine's "Roles". |
| 4 | `04_migrate_statuses.php` | Issue Statuses | Ensures all necessary issue statuses exist in Redmine. |
| 5 | `05_migrate_priorities.php` | Issue Priorities | Ensures all necessary issue priorities exist in Redmine. |
| 6 | `06_migrate_trackers.php` | Trackers | Migrates Jira "Issue Types" to Redmine "Trackers". |
| 7 | `07_migrate_custom_fields.php` | Custom Fields | Migrates custom fields. This can be complex and may require manual mapping decisions. |
| 8 | `08_migrate_projects.php` | Projects | Creates the project containers in Redmine. |
| 9 | `09_assign_members.php` | Project Memberships | Assigns migrated users and groups to migrated projects with the appropriate roles. **Depends on Users, Groups, Roles, Projects.** |
| 10 | `10_migrate_tags.php` | Tags (Labels) | Extracts all unique labels from Jira issues and creates them as tags in Redmine. **Depends on Issues (Extract phase).** |
| 11 | `11_migrate_issues.php` | **Issues** | The main event. Migrates all issues, using the mapping tables to link to the correct Redmine project, tracker, user, status, etc. |
| 12 | `12_migrate_journals.php` | Comments & History | Migrates issue comments and changelogs. **Depends on Issues.** |
| 13 | `13_migrate_attachments.php` | Attachments | Downloads attachments from Jira and uploads them to the corresponding Redmine issues. **Depends on Issues.** |

---

## 4. How Each Script Works: The ETL Pattern

Every migration script should follow the same three-phase logic.

### Phase 1: Extract

*   **From Jira**: Connect to the Jira v3 REST API. Fetch all relevant objects (e.g., all projects). Handle pagination to ensure you get all records. For each object, insert its raw data into the corresponding `staging_jira_*` table. Use an `INSERT... ON DUPLICATE KEY UPDATE` statement to avoid errors on re-runs.
*   **From Redmine**: Connect to the Redmine REST API. Fetch all existing objects of the same type (e.g., all current projects). Clear the `staging_redmine_*` table (`TRUNCATE`) and populate it with the fresh data. This ensures you are always comparing against the latest state of the target system.

### Phase 2: Transform & Reconcile

This is the core logic phase where no API calls are made to create data.

1.  **Populate Mapping Table**: Select all records from the `staging_jira_*` table and insert their primary IDs into the `migration_mapping_*` table with a default status of `PENDING_ANALYSIS`, ignoring duplicates.
2.  **Find Matches**: Join the `migration_mapping_*` table with the `staging_jira_*` and `staging_redmine_*` tables on a logical key (e.g., `user.email`, `project.key`, `group.name`).
    *   If a match is found, `UPDATE` the mapping record's status to `MATCH_FOUND` and store the existing Redmine ID.
3.  **Identify New Items**: For all remaining records in `PENDING_ANALYSIS` status, `UPDATE` their status to `READY_FOR_CREATION`.
4.  **Handle Transformations**: This is where you apply any business logic. For example, you might need to transform Jira's ADF (Atlassian Document Format) for descriptions into Markdown for Redmine, or decide on a default password policy for new users.

### Phase 3: Load

1.  **Select Candidates**: `SELECT` all records from the `migration_mapping_*` table `WHERE status = 'READY_FOR_CREATION'`.
2.  **Loop and Create**: Iterate through the selected records. For each record:
    *   Construct the JSON/XML payload required by the Redmine API.
    *   Make a `POST` request to the appropriate Redmine API endpoint to create the object.
    *   **On Success (e.g., HTTP 201 Created)**: Parse the response to get the new Redmine ID. `UPDATE` the mapping record with the new ID and set the status to `CREATION_SUCCESS`.
    *   **On Failure (e.g., HTTP 422 Unprocessable Entity)**: `UPDATE` the mapping record's status to `CREATION_FAILED` and store the error message from the API response in the `notes` column for later debugging.

By following this structured, database-driven approach, you create a migration process that is reliable, easy to debug, and can be executed in manageable stages.

---

## 5. Running `01_migrate_users.php`

The user migration entry point ships with a few quality-of-life CLI options that make it easier to iterate on specific phases without touching the rest of the workflow.

```bash
php 01_migrate_users.php --help
```

### Available options

| Option | Description |
| --- | --- |
| `-h`, `--help` | Print usage information and exit. |
| `-V`, `--version` | Display the script version (`0.0.1`). |
| `--phases=<list>` | Comma-separated list of phases to run (e.g., `jira`, `redmine`, or both). |
| `--skip=<list>` | Comma-separated list of phases to skip. |

**Default behaviour:** when no phase-related options are supplied, both phases (`jira` extraction and `redmine` snapshot) are executed in order.

### Usage examples

```bash
# Run both phases (default)
php 01_migrate_users.php

# Only refresh the Redmine snapshot
php 01_migrate_users.php --phases=redmine

# Re-run the Jira extraction while skipping the Redmine snapshot
php 01_migrate_users.php --skip=redmine

# Check the script version
php 01_migrate_users.php --version
```
