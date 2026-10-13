# Jira Cloud to Redmine migration

PHP scripts that migrate Jira Cloud projects, users, issues, comments, attachments, links, tags and
watchers to Redmine through a MySQL staging database. Tested on Redmine 5.1 and 7.0.

- [docs/RUNBOOK.md](docs/RUNBOOK.md): how to run a migration, step by step, with the checks after
  each step, the manual steps around the run and the web server settings for large attachments.
- [docs/SCRIPTS.md](docs/SCRIPTS.md): per script, its phases, options and configuration keys.
- [docs/MIGRATION-PLAN.md](docs/MIGRATION-PLAN.md): the test migrations, the findings and the
  decisions behind the current behaviour.

## How it works

Sixteen scripts run in order (`01_migrate_projects.php` to `16_export_workflows.php`), each for one
kind of object. Every script has the same phases:

| phase | does |
|---|---|
| `jira` | extract from Jira into `staging_jira_*` tables (read only) |
| `redmine` | snapshot what already exists in Redmine into `staging_redmine_*` |
| `transform` | match the two and fill `migration_mapping_*`: status, Redmine id, the proposed payload |
| `push` | write to Redmine; only with `--confirm-push`, preview with `--dry-run` |

The mapping tables hold the state of the migration. A rerun skips what is migrated, tries failed rows
again and keeps rows edited by hand (automation hash), so each script can be stopped and run again.

## Requirements

- PHP 8.3 with pdo_mysql, mbstring, dom, curl; Composer; MySQL or MariaDB for staging.
- Jira Cloud: a scoped API token with read scopes only (see the runbook, step 0).
- Redmine 5.1 or 7.0: REST API on, an admin API key, text formatting CommonMark.
- Redmine plugins used when present: [redmine_extended_api](https://github.com/jcatrysse/redmine_extended_api)
  (keeps authors and dates, creates statuses, roles, trackers and fields),
  [redmine_depending_custom_fields](https://github.com/jcatrysse/redmine_depending_custom_fields)
  (cascading fields), redmine_tags (labels), redmine_issue_view_columns (extra relation types).

## Setup

```bash
composer install
mysql <staging_db> < database/schema.sql
php 01_migrate_projects.php --help
```

Put the settings that differ from `config/config.default.php` in `config/config.local.php` (it returns
an array that overrides the defaults key by key). That file is gitignored: secrets go there or in the
environment, never in the repository.

## Development

```bash
tools/qa.sh        # php -l on all, PHPUnit, PHPStan level 8 and PSR-12 on the new code
php tools/verify.php --projects=KEY1,KEY2 --sample=25   # compare Jira and Redmine after a run
```

New code goes in `src/` (namespace `Jira2Redmine\`) with a test in `tests/`. `tools/testenv/` builds
Redmine 5.1 and 7.0 test instances and runs the full sequence against them (`setup.sh`, `run_all.sh`).
