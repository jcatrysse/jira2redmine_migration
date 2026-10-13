# Runbook: a Jira Cloud to Redmine migration

The order, the commands and what to check after each step, as tested on Redmine 5.1 and 7.0 with
GEOS, SIS and OAC (see docs/MIGRATION-PLAN.md for the numbers and the findings behind every step).
`tools/testenv/run_all.sh` runs the same sequence against the test environment.

Every script has the phases `jira` (extract), `redmine` (snapshot), `transform` (mapping) and `push`
(write). Nothing is written to Redmine without `--confirm-push`; preview with `--dry-run` first.
Every script can be rerun: rows already migrated are skipped, failed rows are tried again.

## 0. Before the run

| what | how | check |
|---|---|---|
| Jira token | scoped API token, read scopes only (decision 1); `jira.base_url` the site, `jira.cloud_id` from `https://<site>/_edge/tenant_info` | write probe: `PUT /rest/api/3/issue/<NONEXIST-1>` through the gateway answers 401 "scope does not match" |
| Redmine | REST API on, CommonMark, an admin API key, `redmine_extended_api` installed | `GET /extended_api/...` answers with header `X-Redmine-Extended-API` |
| Config | `config/config.local.php`: Jira, Redmine, staging DSN, `migration.issues.projects`, `default_redmine_author_id` (for app accounts and people without e-mail) | `php 01_migrate_projects.php --phases=jira` extracts the projects (0 projects stops with the causes) |
| Staging | empty database from `database/schema.sql` | |
| Redmine, manual (decide per item, plan work list 15) | open workflows for the new trackers (otherwise every issue is created in the default status); switch off attachment prune, LDAP sync, stealth mode; give the roles that watch issues `view_watched_issues` (redmine_view_issue_description) | |

## 1. Structure

```bash
php 01_migrate_projects.php && php 01_migrate_projects.php --phases=push --confirm-push
php 02_migrate_users.php    && php 02_migrate_users.php    --phases=push --confirm-push
php 03_migrate_groups.php   && php 03_migrate_groups.php   --phases=push --confirm-push
php 04_migrate_roles.php    && php 04_migrate_roles.php    --phases=push --confirm-push
php 05_migrate_statuses.php && php 05_migrate_statuses.php --phases=push --confirm-push
php 06_migrate_priorities.php && php 06_migrate_priorities.php --phases=push --confirm-push
php 07_migrate_trackers.php && php 07_migrate_trackers.php --phases=push --confirm-push
```

Check after each: the summary line `Matched / Ready / Manual`. Users: app accounts are IGNORED, people
without a visible e-mail are MANUAL (their content uses the default author). Trackers longer than 30
characters are shortened (note on the mapping row).

## 2. Custom fields, issues and attachments

```bash
php 08_migrate_custom_fields.php --phases=jira
php 10_migrate_issues.php --phases=jira
php 09_migrate_attachments.php --phases=jira
php 08_migrate_custom_fields.php --phases=usage,redmine,transform
php 08_migrate_custom_fields.php --phases=push --confirm-push
php 09_migrate_attachments.php --phases=pull --confirm-pull
php 09_migrate_attachments.php --phases=transform,push --confirm-push
php 10_migrate_issues.php --phases=transform,push --confirm-push
```

- `attachments.download_concurrency` speeds up the pull; downloads are latency bound per file.
- One run of 10 creates the issues with their description and then sets parents and rewritten Jira
  links. Check `migration_mapping_issues`: no READY_FOR_REWRITE except issues whose parent failed.

## 3. History and links

```bash
php 11_migrate_journals.php --confirm-push --use-extended-api
php 12_migrate_issue_relations.php --confirm-push
php 13_migrate_tags.php --confirm-push
php 14_migrate_watchers.php --confirm-push
php 15_migrate_checklists.php --confirm-push
php 16_export_workflows.php
```

- 12 needs the GEOxyz relation types configured in `redmine_issue_view_columns.local.rb` on the
  Redmine server (see `tools/testenv/redmine_issue_view_columns.local.rb`).
- 14 reads back each issue's watchers: a FAILED row names a missing permission.
- 13 uses `POST /issues/:id/tags.json` on 5.1 and the issue update on 7.0 (detected).

## 4. Verify

```bash
php tools/verify.php --projects=GEOS,SIS,OAC --sample=25
```

Green means every check passes. The report holds issue keys: keep it out of the repository.

## 5. After the run

Reset the workflows, lock the users that were activated for the migration, switch prune, LDAP sync
and stealth mode back on (only the steps done in 0).

## Appendix: Apache for the new server (large attachments)

Measured 2026-10-13 on Redmine 7.0 behind Apache 2.4.58 + Passenger 6.0.17 with the two largest
attachments in Jira (a 431 MB video and a 1,811 MB tif), uploaded the way 09 does it:

| | without X-Sendfile (Puma or Passenger alone) | Apache + Passenger + X-Sendfile |
|---|---|---|
| upload 431 MB / 1,811 MB | 4.8 s / 23 s (Puma direct) | 13.0 s / 54.0 s |
| app memory during upload | +140 MB / +210 MB | +113 MB / +110 MB |
| download 431 MB / 1,811 MB | 0.6 s / 2.8 s (7.0); on 5.1 the app holds the whole file in memory (275 to 2,087 MB) | 0.6 s / 2.6 s, app memory flat (Apache sends the file) |
| range request `bytes=1000-1999` | 200, whole file: a video cannot be seeked | **206**, 1,000 bytes: seekable |
| 1.8 GB upload with `LimitRequestBody` 1 GiB (Apache's default since 2.4.53) | | **413**, refused before any byte is sent; 431 MB passes |

Recommended virtual host (the tested one is `tools/testenv/apache-redmine70.conf`):

```apache
<VirtualHost *:443>
    DocumentRoot /srv/redmine/public
    PassengerAppRoot /srv/redmine
    PassengerAppEnv production
    # Uploads: above the largest attachment (Jira's largest is 1.8 GB) and Redmine's attachment_max_size.
    LimitRequestBody 4294967296
    # A 1.8 GB upload took 54 s here; leave room for slower links.
    TimeOut 600
    # Downloads: Rails hands the file to Apache, which serves it with range requests (seekable video)
    # and without holding it in the application's memory.
    XSendFile On
    XSendFilePath /srv/redmine/files
    SetEnv REDMINE_X_SENDFILE_HEADER X-Sendfile
    <Directory /srv/redmine/public>
        Require all granted
        Options -MultiViews
    </Directory>
</VirtualHost>
```

with `config/additional_environment.rb`:

```ruby
config.action_dispatch.x_sendfile_header = ENV['REDMINE_X_SENDFILE_HEADER'] if ENV['REDMINE_X_SENDFILE_HEADER'].to_s != ''
```

(or set the header unconditionally when Redmine only ever runs behind this Apache). Also set Redmine's
`attachment_max_size` (Administration, Settings, Files) above the largest attachment, in KB.

For a migration onto the current 5.1 server (Apache defaults, no X-Sendfile): attachments above 1 GiB
are refused with 413; offload them (SharePoint, `attachments.sharepoint.offload_threshold_bytes`) or
raise `LimitRequestBody` for the migration.
