# Jira Cloud to Redmine migration

PHP scripts that migrate Jira Cloud projects, users, issues, comments, attachments, links, tags, watchers and the
history of status, assignee and priority to Redmine through a MySQL staging database. Tested on Redmine 5.1 and 7.0.

**GEOxyz starts here: [docs/PRODUCTION.md](docs/PRODUCTION.md)**: the upgrade of Redmine 5.1 to 7.0, the migration of
all projects in one maintenance window, and the prompt for the local Claude session that runs both on the server.

- [docs/RUNBOOK.md](docs/RUNBOOK.md): the generic run, step by step, with the checks after each step, the manual steps
  around the run and the web server settings for large attachments.
- [docs/SCRIPTS.md](docs/SCRIPTS.md): per script, its phases, options and configuration keys.
- [docs/MIGRATION-PLAN.md](docs/MIGRATION-PLAN.md): the test migrations, the findings and Jan's decisions.
- [docs/research/JIRA-HISTORY.md](docs/research/JIRA-HISTORY.md): the research behind the history step.

## How it works

Sixteen scripts run in order (`01_migrate_projects.php` to `16_export_workflows.php`), each for one kind of object.
Every script has the same phases:

| phase | does |
|---|---|
| `jira` | extract from Jira into `staging_jira_*` tables (read only) |
| `redmine` | snapshot what already exists in Redmine into `staging_redmine_*` |
| `transform` | match the two and fill `migration_mapping_*`: status, Redmine id, the proposed payload |
| `push` | write to Redmine; only with `--confirm-push`, preview with `--dry-run` |

The mapping tables hold the state of the migration. A rerun skips what is migrated, tries failed rows again and keeps
rows edited by hand (automation hash), so each script can be stopped and run again.

- **Authors and times as in Jira**; post-processing leaves no journal and no new updated time (decision 37).
- **Jira's history** (decision 41): 11 writes the status, assignee and priority changes as journals with their own
  author and time, per issue in Jira's time order between the comments ("Last updated by" stays the last actor in
  Jira). A value Redmine does not have is left out, the chain connected; such an issue gets `jira-history.json`.
- **Authors without an account** (decision 42): Jira content whose author has no Redmine account goes to the user that
  `J2R_DEFAULT_AUTHOR_ID` names, Redmine's Anonymous user unless chosen otherwise. It is required: without it the
  scripts stop instead of writing as the migration account.

## Requirements

- PHP 8.2 or newer with pdo_mysql, mbstring, dom, curl; Composer; MySQL or MariaDB for staging.
- Jira Cloud: a scoped API token with read scopes only (the runbook, step 0).
- Redmine 5.1 or 7.0: REST API on, an admin API key, text formatting CommonMark.
- [redmine_extended_api](https://github.com/jcatrysse/redmine_extended_api) `b423b4c` or later (authors and dates,
  `skip_journal`, the history endpoint); used when present:
  [redmine_depending_custom_fields](https://github.com/jcatrysse/redmine_depending_custom_fields) (cascading fields),
  redmine_tags (labels), redmine_issue_view_columns (extra relation types).

## Setup

```bash
composer install
mysql <staging_db> < database/schema.sql
php 01_migrate_projects.php --help
```

Put the settings that differ from `config/config.default.php` in `config/config.local.php` (it returns an array that
overrides the defaults key by key). That file is gitignored: secrets go there or in the environment, never in the
repository.

## Development

```bash
tools/qa.sh        # php -l on all, PHPUnit, PHPStan level 8, PSR-12
php tools/verify.php --projects=KEY1,KEY2 --sample=25   # compare Jira and Redmine after a run
```

PHPStan level 8 and PSR-12 (140 characters) cover `src/`, `tests/`, the 16 scripts and `lib/cli.php`,
`lib/jira_gateway.php`, `lib/verify.php` (PHPStan also `lib/requirements.php`); PHPStan analyses each script on its own,
with a baseline only for what cannot be fixed without a change of behaviour (`tools/phpstan-baseline/`, each entry with
its reason). The scripts share their start, options, configuration, database, clients and clock through `src/`
(namespace `Jira2Redmine\`, [SCRIPTS.md](docs/SCRIPTS.md), "The command line and the shared code"). New code goes in
`src/` with a test in `tests/`. `tools/testenv/` builds Redmine 5.1 and 7.0 test instances and runs the full sequence
(`setup.sh`, `run_all.sh`; `J2R_JIRA_TAPE` replays a recorded Jira).
