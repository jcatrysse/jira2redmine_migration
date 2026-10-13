# Runbook: a Jira Cloud to Redmine migration

The order, the commands and what to check after each step. Tested on Redmine 5.1 and 7.0 with GEOS,
SIS and OAC (426 issues, 1,101 comments, 576 attachments); the numbers and the findings behind each
step are in docs/MIGRATION-PLAN.md. `tools/testenv/run_all.sh 51|70 LABEL` runs this sequence against
the test environment, verify included.

Every script has the phases `jira` (extract), `redmine` (snapshot), `transform` (mapping) and `push`
(write). Nothing is written to Redmine without `--confirm-push`; preview with `--dry-run` first. Every
script can be rerun: migrated rows are skipped, failed and manual rows are judged again, rows edited by
hand in the mapping tables keep their edits (automation hash). Checked on 5.1 and 7.0 by running 10 to
14 a second time: nothing changes. Do not rerun 11 from `main` on a target: before B35 every rerun of
11 created all journals again.

## 0. Before the run

| what | how | check |
|---|---|---|
| Jira token | scoped API token with read scopes only (decision 1, plan): `read:jira-work`, `read:jira-user` and 21 granular scopes (each `:jira`): read:group, read:user, read:avatar, read:project, read:project-category, read:project-role, read:application-role, read:issue-type, read:issue-security-level, read:status, read:webhook, read:workflow, read:workflow-scheme, read:issue-type-scheme, read:issue-type-screen-scheme, read:screen, read:screen-scheme, read:screen-tab, read:screenable-field, read:field-configuration, read:field-configuration-scheme (an operation needs all the scopes it lists) | write probe through the gateway, `PUT /rest/api/3/issue/NONEXIST-1`: 401 "scope does not match" (a 404 means the token can write) |
| Jira config | `jira.base_url` the site (`https://<site>.atlassian.net`, used for links back), `jira.cloud_id` from `https://<site>/_edge/tenant_info` (all API calls then go through the gateway) | `php 01_migrate_projects.php --phases=jira` lists the projects; 0 projects stops with the likely causes |
| Redmine | REST API on, text formatting CommonMark, an admin API key, `redmine_extended_api` installed, `attachment_max_size` above the largest attachment | `curl -H "X-Redmine-API-Key: ..." <redmine>/extended_api/issues.json` answers with header `X-Redmine-Extended-API` |
| Redmine settings | parent priority independent (Administration, Settings, Issue tracking: parent attributes); cross-project subtasks "With all projects" and cross-project issue relations allowed (Jira parents and links can be in another project, B38, B39; open for Jan); GEOxyz relation types in `redmine_issue_view_columns.local.rb` (`tools/testenv/redmine_issue_view_columns.local.rb` is generated from `migration.relations.custom_mappings`) | |
| Config | `config/config.local.php`: staging DSN, `migration.issues.projects` (the projects of this run), `migration.issues.default_redmine_author_id` and `migration.journals.default_redmine_author_id` (content of Jira app accounts and of people without a visible e-mail), `attachments.download_concurrency` (6 is safe) | |
| Staging | an empty MySQL database from `database/schema.sql` | |
| Web server | see the appendix: X-Sendfile, `LimitRequestBody` above the largest attachment, `TimeOut` 600; `SetEnv RACK_QUERY_PARSER_PARAMS_LIMIT 12000` (large custom field forms) | |

### Manual steps around the run (each a choice, see "Choices for Jan" below)

`tools/testenv/manual_steps.rb` does the first three on the test instances:

1. **Open the workflows** (`J2R_STEP=workflows`): every transition for the roles that create issues, new
   issues included. Without it every issue is created in its tracker's default status (B12). The step
   records what it adds; `J2R_STEP=restore` removes exactly that after the run.
2. **Activate locked users and add them to the projects** (`J2R_STEP=members`, after 04): Redmine refuses
   a locked user or a non-member as assignee (B5). Activated users are flagged and locked again by
   `restore`. 04 adds only what Jira's project roles hold (at GEOxyz the project administrators); Jira
   grants project access through permission schemes, so the step gives every user the Developer role
   in the run's projects (open question for Jan in the plan, B31).
3. **Watch permission**: roles need `view_watched_issues` (redmine_view_issue_description), otherwise
   Redmine drops watchers silently (14 now reports them as FAILED with the reason).
4. Switch off for the duration: attachment prune (uploads wait for their issue; prune deletes unattached
   uploads after 24 h), LDAP sync, stealth mode if used.

## 1. Structure

```bash
php 01_migrate_projects.php && php 01_migrate_projects.php --phases=push --confirm-push
php 02_migrate_users.php    && php 02_migrate_users.php    --phases=push --confirm-push
php 03_migrate_groups.php   && php 03_migrate_groups.php   --phases=push --confirm-push
# members wait for their group: a second pass once the groups exist
php 03_migrate_groups.php --skip=jira && php 03_migrate_groups.php --phases=push --confirm-push
php 04_migrate_roles.php    && php 04_migrate_roles.php    --phases=push --confirm-push
php 05_migrate_statuses.php && php 05_migrate_statuses.php --phases=push --confirm-push
php 06_migrate_priorities.php && php 06_migrate_priorities.php --phases=push --confirm-push
php 07_migrate_trackers.php && php 07_migrate_trackers.php --phases=push --confirm-push
```

Check the summary line of each (`Matched / Ready / Manual`):
- users: Jira app accounts are IGNORED; people without a visible e-mail stay MANUAL (their content uses
  the default author); new users are created LOCKED unless `default_redmine_user_status` says otherwise;
- trackers: names longer than 30 characters are shortened (note on the mapping row);
- statuses and trackers: names in the language of the Redmine instance; Jira `Done` maps to a closed
  status, `To Do` to New;
- 03, 04, 16 need the granular read scopes; without them they stop at the first 401;
- groups: the first pass creates the groups (members AWAITING_GROUP), the second adds the members;
  members whose Jira account is not migrated stay AWAITING_USER;
- roles: `migration.roles.mappings` maps Jira role names to Redmine roles (default `Administrators` =>
  `Manager`, `atlassian-addons-project-access` ignored); a user in a Jira project role becomes a member
  of that project, a group in it a group member; nothing is given to a user's other groups.

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

- 08 enables each field for the projects and trackers where Jira issues hold values, not only where
  Jira shows the field today; cascading fields get their dependencies as option ids.
- 10 creates every issue with its description, then sets parents and rewrites Jira links to `#id`
  in the same run. Check `migration_mapping_issues`: READY_FOR_REWRITE only for issues whose parent is
  in a project outside the run.
- A parent with an open subtask but a closed Jira status gets the open status "Resolved" (Jan's rule;
  the run stops if that status is missing or closed); the transform prints each `[open-parent]`.
- Attachments keep their Jira name; a second attachment with the same name on the issue gets the
  prefix `{jiraId}__`, so inline images show the right file.

## 3. History and links

```bash
php 11_migrate_journals.php --confirm-push --use-extended-api
php 12_migrate_issue_relations.php --confirm-push
php 13_migrate_tags.php --confirm-push
php 14_migrate_watchers.php --confirm-push
php 15_migrate_checklists.php --confirm-push
php 16_export_workflows.php
```

- Mentions become `@login` (Redmine's mention syntax).
- 13 uses `POST /issues/:id/tags.json` where redmine_tags has it (5.1) and the issue update elsewhere
  (7.0), detected on the first call.
- 14 reads back each issue's watchers; a FAILED row names the reason.

## 4. Verify

```bash
php tools/verify.php --projects=GEOS,SIS,OAC --sample=25
```

Green means every check passes. The report holds issue keys and short Jira texts: keep it out of the
repository. With all fixes GEOS, SIS and OAC are green on 5.1 and 7.0 (21 checks, 0 failures). A
journal present twice on an issue fails the run; with `attachments.test_sample` the files skipped by
the sample are expected to be absent.

## 5. After the run

`J2R_STEP=restore` (or by hand): remove the opened workflow transitions, lock the activated users
again; check the group memberships; switch prune, LDAP sync and stealth mode back on.

## Choices for Jan (plan work list 15, decided 2026-10-13)

| manual step today | options | recommendation |
|---|---|---|
| open workflows, reset after | (a) script it (done: `workflows` / `restore`); (b) Extended API import mode that skips the workflow and initial-status checks (session A) | **Jan: (a)** |
| activate locked users, lock after | (a) script it (done: `members` / `restore`); (b) Extended API import mode that accepts locked users as author and assignee | **Jan: (a)** |
| attachment prune off | (a) prune off during the run; (b) upload each attachment in 10 right before its issue is created, so nothing waits 24 h | **Jan: (a)**, runbook step, no code change |
| LDAP sync and stealth mode off | runbook step | runbook step |

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
