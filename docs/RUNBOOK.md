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

## Prerequisites (Jan, decision 23)

The scripts run on an existing server with PHP 8.2 or newer and a temporary MySQL or MariaDB for the staging
database, which is removed after the migration (no PostgreSQL port of the staging SQL).

1. **PHP 8.2 or newer (CLI)** with the extensions pdo, pdo_mysql, mbstring, ctype, json, dom, libxml, curl
   and openssl. Debian/Ubuntu: `apt install php8.2-cli php8.2-mysql php8.2-mbstring php8.2-xml php8.2-curl`
   (json, ctype, openssl and pdo come with the CLI package). Check: `php -v`, `php -m`. Every script checks
   this first and stops with `[ERROR] This PHP cannot run the migration scripts:` and what is missing. The
   CLI's default `memory_limit` (-1 on Debian) is what the test runs used. Tested on PHP 8.2.34 and 8.3.6.
   In the repository checkout: `composer install --no-dev`. For `tools/qa.sh` (developers only) also
   pdo_sqlite (`php8.2-sqlite3`) and the dev dependencies.
2. **A temporary MySQL 8 or MariaDB** on the same server (or one it reaches), with the staging database and
   its user:

   ```bash
   apt install mariadb-server
   mysql -e "CREATE DATABASE jira2redmine CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
     CREATE USER 'jira2redmine'@'localhost' IDENTIFIED BY '<password>';
     GRANT ALL ON jira2redmine.* TO 'jira2redmine'@'localhost';"
   mysql jira2redmine < database/schema.sql
   ```

   In `config/config.local.php`: `database.dsn` (`mysql:host=127.0.0.1;port=3306;dbname=jira2redmine;charset=utf8mb4`),
   `database.username`, `database.password`. Room on disk: the staging database holds the Jira payloads of every
   migrated issue and comment, and `paths.tmp` the attachments of the run until 09 has pushed them (all 14
   projects about 116 GiB, SURV alone 98.7 GiB; docs/PRODUCTION.md B2).
3. **After the migration**, once the run (or the last batch), the relink check (section 1) and the workflows
   (section 5) are done and no rerun is needed: in production staging is kept as an encrypted dump, read back
   before anything is dropped (Jan, decision 41; docs/PRODUCTION.md B9 step 6); outside production, if a copy is
   wanted, `mysqldump` it and keep it outside this repository (it holds Jira data); then `mysql -e "DROP DATABASE jira2redmine; DROP USER
   'jira2redmine'@'localhost';"`, `apt purge mariadb-server` (if installed for this), and empty `paths.tmp`.

## 0. Before the run

| what | how | check |
|---|---|---|
| Jira token | scoped API token with read scopes only (decision 1, plan): `read:jira-work`, `read:jira-user` and 21 granular scopes (each `:jira`): read:group, read:user, read:avatar, read:project, read:project-category, read:project-role, read:application-role, read:issue-type, read:issue-security-level, read:status, read:webhook, read:workflow, read:workflow-scheme, read:issue-type-scheme, read:issue-type-screen-scheme, read:screen, read:screen-scheme, read:screen-tab, read:screenable-field, read:field-configuration, read:field-configuration-scheme (an operation needs all the scopes it lists) | write probe through the gateway, `PUT /rest/api/3/issue/NONEXIST-1`: 401 "scope does not match" (a 404 means the token can write) |
| Jira config | `jira.base_url` the site (`https://<site>.atlassian.net`, used for links back), `jira.cloud_id` from `https://<site>/_edge/tenant_info` (all API calls then go through the gateway) | `php 01_migrate_projects.php --phases=jira` lists the projects; 0 projects stops with the likely causes |
| Redmine | REST API on, text formatting CommonMark, an admin API key, `redmine_extended_api` installed (`b423b4c` or later: its history endpoint, decision 41), `attachment_max_size` above the largest attachment | `curl -H "X-Redmine-API-Key: ..." <redmine>/extended_api/issues.json` answers with header `X-Redmine-Extended-API`; `ls <redmine-root>/plugins/redmine_extended_api/app/controllers/extended_api_issue_histories_controller.rb` exists |
| Redmine settings | parent priority independent (Administration, Settings, Issue tracking: parent attributes); cross-project subtasks "With all projects" and cross-project issue relations allowed (Administration, Settings, Issue tracking; Jira parents and links can be in another project, B38, B39; confirmed by Jan 2026-10-14; `manual_steps.rb` sets both in its `workflows` step on the test instances); both **stay on after the migration**, or Redmine refuses to save an issue whose parent or related issue is in another project; GEOxyz relation types in `redmine_issue_view_columns.local.rb` (`tools/testenv/redmine_issue_view_columns.local.rb` is generated from `migration.relations.custom_mappings`) | |
| Config | `config/config.local.php`: staging DSN, `migration.issues.projects` (the projects of this run), `migration.issues.default_redmine_author_id` and `migration.journals.default_redmine_author_id` (content of Jira app accounts and of people without a visible e-mail), `attachments.download_concurrency` (6 is safe) | |
| Staging | an empty MySQL database from `database/schema.sql`; a database from an earlier version of the scripts needs the tables added since (2026-10-14: `staging_jira_project_permission_grants`, `staging_jira_project_permission_holders`, `staging_redmine_builtin_groups`; 2026-10-15: the column `migration_mapping_trackers.created_by_migration`, `ALTER TABLE migration_mapping_trackers ADD created_by_migration TINYINT(1) NOT NULL DEFAULT 0 AFTER proposed_default_status_id`; in such a database a tracker 07 created earlier counts as existing, decision 34; 2026-10-19: `ALTER TABLE migration_mapping_journals ADD history_journal_id INT NULL AFTER pushed_text_hash, ADD history_attachment_id INT NULL AFTER history_journal_id`, decision 41) | |
| Automation rules | not migrated (no Jira platform API, 16 says so in its log). Jan exports them once himself (confirmed 2026-10-14): as a Jira admin, Settings (cog) > System > Automation rules (global automation, `https://<site>.atlassian.net/jira/settings/automation`), menu "..." > Export rules: one JSON file with every rule, global and per project. Keep the file outside this repository (it is public): **never commit it**, not even in a branch (`.gitignore` stops `automation-rules*.json` as a safety net). Use it as the checklist for what to rebuild in Redmine (custom workflows, recurring tasks) | the file opens as JSON and lists the rules the automation page shows |
| Web server | see the appendix: X-Sendfile, `LimitRequestBody` above the largest attachment, `TimeOut` 600; `SetEnv RACK_QUERY_PARSER_PARAMS_LIMIT 12000` (large custom field forms) | |

### Manual steps around the run (each a choice, see "Choices for Jan" below)

`tools/testenv/manual_steps.rb` does the first three on the test instances:

1. **Open the workflows** (`J2R_STEP=workflows`): every transition, new issues included, for the roles in
   `migration.workflows.open_workflow_roles` (Jan, decision 33; default Manager and Developer), passed as
   `J2R_WORKFLOW_ROLES="$(php tools/config_value.php migration.workflows.open_workflow_roles)"`. Without it every
   issue is created in its tracker's default status (B12). A role the Redmine lacks, or one without Add or Edit
   issues, stops the step. The import runs as an admin, who gets every role's transitions: one role is enough, and
   `["Migration (temporary)"]` (created by the step) keeps the open workflow away from existing projects. The step
   records what it adds; `J2R_STEP=restore` removes exactly that after the run.
2. **Activate locked users and give the temporary migration role** (`J2R_STEP=members`, after 04's
   push): Redmine refuses a locked user as author or assignee (B5); activated users are flagged and
   locked again by `restore`. Jan's decision 12: every migrated user also becomes a member of every
   migrated project (`J2R_PROJECTS`) with the role `Migration (temporary)` (every permission,
   assignable), on top of 04's final memberships, so Redmine accepts every historical assignee and
   watcher. `J2R_USER_IDS_FILE` (one Redmine user id per line, from `migration_mapping_users`) limits it
   to the migrated users. `restore` removes that role and the memberships left without a role; 04's
   memberships stay (see "Review the proposed memberships" in section 1).
3. **Watch permission**: roles need `view_watched_issues` (redmine_view_issue_description), otherwise
   Redmine drops watchers silently (14 now reports them as FAILED with the reason).
4. Switch off for the duration: attachment prune (uploads wait for their issue; prune deletes unattached
   uploads after 24 h), LDAP sync, and redmine_checklists' "issue done ratio" (15 would save the issue through the
   plain API, with mail and webhooks). Every other issue write goes through the Extended API with notify=false, 13
   on 7.0 and `tools/relink_issue_links.php` included (Jan, 2026-10-15): no mail, no Redmine 7 webhook. As a cheap
   safety net, stealth mode on for the migration account (redmine_stealth, `POST /stealth/toggle.json` with
   `toggle=true`; signing in to the web interface as that account switches it off again). See
   docs/PRODUCTION.md, B5.

## 1. Structure

```bash
php 01_migrate_projects.php && php 01_migrate_projects.php --phases=push --confirm-push
php 02_migrate_users.php    && php 02_migrate_users.php    --phases=push --confirm-push
php 03_migrate_groups.php   && php 03_migrate_groups.php   --phases=push --confirm-push
# members wait for their group: a second pass once the groups exist
php 03_migrate_groups.php --skip=jira && php 03_migrate_groups.php --phases=push --confirm-push
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
- priorities (Jan, decision 35): a Jira priority maps onto an existing Redmine priority, the one
  `migration.priorities.mappings` names (Jira name => Redmine name) or else the one of the same name; the rest
  is MANUAL with a note and 06 creates none (only with `migration.priorities.create_missing`, then after the
  existing ones, B73). Map the MANUAL rows in the configuration and run 06 again; 10 holds back the issues of an
  unmapped priority;
- 03, 04, 16 need the granular read scopes; without them they stop at the first 401;
- groups: the first pass creates the groups (members AWAITING_GROUP), the second adds the members;
  members whose Jira account is not migrated stay AWAITING_USER;
- roles: `migration.roles.mappings` maps Jira role names to Redmine roles (default `Administrators` =>
  `Manager`, `atlassian-addons-project-access` ignored); a user in a Jira project role becomes a member
  of that project, a group in it a group member; nothing is given to a user's other groups.

### Review the proposed memberships (04, before its push)

04 runs after the jira phases of 08, 10, 09 and 14 (section 2). By default it proposes only the
memberships Jira's permission model gives: historical assignees and watchers without access are covered
during the import by the temporary migration role of the `members` step (Jan, decision 12). With
`migration.roles.assignee_only_members` or `watcher_only_members` set to true it also proposes them as
direct members (B46, B50), READY, with `migration.roles.assignee_only_role` / `watcher_only_role`; those
memberships stay after the run, which is why decision 12 replaced them.

Jira gives project access in two places, and 04 proposes a Redmine membership for both (B31, Jan
option a), in `migration_mapping_project_role_groups`, one row per project, Jira role and principal:

| Jira | `jira_role_name` | `principal_type` | proposed role |
|---|---|---|---|
| a group with Browse projects in the project's permission scheme | `Browse projects (permission scheme)` | group | Developer |
| a group with Assignable user (Redmine assigns only to members) | `Assignable user (permission scheme)` | group | Developer |
| a group with Administer projects | `Administer projects (permission scheme)` | group | Manager |
| a user or group in the project role Administrators | `Administrators` | user / group | Manager |
| the project role atlassian-addons-project-access (Jira apps) | `atlassian-addons-project-access` | user | IGNORED |
| only with `assignee_only_members`: an issue's assignee that none of the above makes a member (Jira keeps an assignee who lost the permission; Redmine refuses the issue, B46) | `Assignee of issues (no membership)` | user | `migration.roles.assignee_only_role` (Developer), READY, note "Assignee only, not in the project's permission groups" |
| only with `watcher_only_members`: an issue's watcher that none of the above makes a member (Jira keeps a watcher who lost access; Redmine drops the watcher, B50) | `Watcher of issues (no membership)` | user | `migration.roles.watcher_only_role` (Developer), READY, note "Watcher only, not in the project's permission groups" |
| a permission granted to an application role ("every licensed user") | the permission's pseudo role, `jira_group_id` `jira-application-role` | group: Redmine's built-in "Non member users" | the mapped role, status MANUAL_INTERVENTION_REQUIRED until you set READY_FOR_ASSIGNMENT: it gives the role to every logged-in user who is not a member (it does not make them assignable) |

The three permission-scheme names are pseudo roles (`jira_role_id` -1, -3, -2), the assignee-only and watcher-only rows -4 and -5; `migration.roles.mappings`
maps them like any role name. A grant to another holder type (a single user, the reporter) is not
proposed; 04 prints it as `[note]`. A group member is a Redmine group membership: its users
get the role through the group, nothing is expanded per user.

```sql
SELECT mapping_id, jira_project_key, jira_role_name, principal_type, jira_group_name,
       redmine_role_id, proposed_redmine_role_name, migration_status, notes
FROM migration_mapping_project_role_groups
WHERE migration_status <> 'IGNORED'
ORDER BY jira_project_key, principal_type, jira_group_name;

-- not wanted:
UPDATE migration_mapping_project_role_groups SET migration_status = 'IGNORED', notes = 'review: ...'
WHERE mapping_id = ...;
-- another role (ids in staging_redmine_roles):
UPDATE migration_mapping_project_role_groups
SET redmine_role_id = 5, proposed_redmine_role_id = 5, proposed_redmine_role_name = 'Reporter'
WHERE mapping_id = ...;
```

A row you change keeps your values on every rerun of 04 (its `automation_hash` no longer matches; 04
prints `[preserved]`). The push sends only `READY_FOR_ASSIGNMENT` rows; a principal that already is a
member gets the role added to its membership, inherited roles stay.

### Batches (several runs, project by project)

Run every batch with all projects migrated so far plus the new ones (`migration.issues.projects`,
`J2R_PROJECTS` in the test environment): the jira phases of 07 and 08 read only the run's projects and
replace their staging tables, so a batch with the new projects alone would narrow what 08 knows of the
earlier ones. Migrated issues, journals and attachments are skipped on the rerun. An issue whose Jira
parent is in a later batch waits (READY_FOR_REWRITE); the batch that migrates the parent's project
links it. Every 10 run lists the issues still waiting, with the parent's key, per parent project
(`Issues waiting for a parent outside the run (B38)`, `[waiting]` lines); a parent that is never
migrated stays on that list: link it by hand or leave it.

**Production: one run of all 14 projects (Jan, decision 39, 2026-10-15)**, as in docs/PRODUCTION.md B7: every
issue exists before 11 and 12, so the relink below is only the check that 0 links are left. The batches below are
its fallback (docs/PRODUCTION.md B11).

**Fallback procedure: batches (Jan, 2026-10-14: batches plus relink; since decision 39 the fallback).**

1. Batch order for production (Jan, decision 22): (1) WIKI; (2) GEOS, SIS, OAC; (3) SURV, DOC, LABO;
   (4) ICT, LLE, SOIL, SSPT, ROV, GSPP, FPS. **TEST is not migrated**: `migration.projects.not_migrated`
   lists it, 01 marks it IGNORED and does not create it. The order puts first what other projects link to
   or hang under: GEOS issues hang under WIKI epics, and a first batch of GEOS, SIS and OAC left 49 relations
   whose other side was in WIKI (30), SURV (13), DOC (5) and LABO (1). Any order works with the steps below;
   this one keeps the waiting lists short.
2. Each batch: 01 to 16 with `migration.issues.projects` = every project migrated so far plus the new
   ones. 12 runs in every batch: a relation whose other side was not migrated yet stays MANUAL ("Missing
   Redmine issue mapping") and 12 creates it in the batch that migrates that side (proved on 7.0: 30 of
   the first batch's 49 created by the WIKI batch). 10 links a waiting parent the same way. Check per
   batch: 12's MANUAL count and 10's `[waiting]` lines only name projects of later batches.
3. After the last batch, the relink (links in text, B52):

   ```bash
   php tools/relink_issue_links.php                # preview
   php tools/relink_issue_links.php --confirm-push # rewrite
   php tools/relink_issue_links.php                # the check
   ```

   It rewrites links to issues a later batch migrated in descriptions and comments ("#123", or
   "text (#123)"), only where Redmine's text is still what the migration pushed (10 and 11 store a hash of
   it); a text edited in Redmine since is left alone and listed as `[edited]`.
4. Verify: the check's last line, `Texts that still hold a Jira link to a migrated issue after this run`,
   must be **0**. A text it lists as `[left]` was edited in Redmine and still has such a link: fix it by
   hand, then run the check again. Also after the last batch: 12's MANUAL relations and 10's `[waiting]`
   parents name only projects that are not migrated. Proved on 7.0 (test, 2026-10-14): 13 texts relinked,
   the check then 0.

## 2. Custom fields, issues and attachments

```bash
php 08_migrate_custom_fields.php --phases=jira
php 10_migrate_issues.php --phases=jira
php 09_migrate_attachments.php --phases=jira
php 14_migrate_watchers.php --phases=jira
php 04_migrate_roles.php    # review the proposed memberships (section 1), then:
php 04_migrate_roles.php    --phases=push --confirm-push
# J2R_STEP=members: activate locked users, the temporary migration role (decision 12, section 0)
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

### Fields that are not migrated (Jan, decision 20)

`migration.custom_fields.not_migrated` lists Jira field types 08 does not create: the service desk fields
"Organizations" and "Request participants". 08 marks them IGNORED with a note (not MANUAL), so they do not
show up as work in its summary or in verify. **Their values stay in Jira only**; Redmine has no copy.

### Select values Jira no longer offers (B37)

Jira keeps a removed option on the issues that have it. 08 adds such a value to the Redmine field, so
10 can set it, and the transform lists every one: `Values Jira no longer offers, kept on the Redmine
field (B37): N` and one `[unoffered] field (id) | value | issues | format: how to retire` line each.
After the run, retire them per Redmine field format (08 creates select fields as `enumeration`):

| Redmine format | retire the value | issues that keep it (5.1 and 7.0 the same) |
|---|---|---|
| enumeration (key/value list, 08's default) | Administration, Custom fields, the field, Values: untick Active | shown; the edit form still offers it, selected, on those issues only; a save keeps it (browser, REST PUT 204); a new issue or another issue cannot get it (422 "is not included in the list") |
| list (plain list) | remove the line from Possible values | the same: shown, kept on edit and save, refused on other issues |

Checked with `tools/testenv/retired_values.rb` and `retired_values.mjs` (browser and REST) on both
targets.

## 3. History and links

```bash
php 11_migrate_journals.php --confirm-push --use-extended-api
php 12_migrate_issue_relations.php --confirm-push
php 13_migrate_tags.php --confirm-push
php 14_migrate_watchers.php --phases=transform,push --confirm-push   # its jira phase ran in section 2
php 15_migrate_checklists.php --confirm-push
php 16_export_workflows.php
```

- Mentions become `@login` (Redmine's mention syntax).
- 11 writes Jira's history of status, assignee and priority too (its phase `history`, decision 41): per issue in
  Jira's time order between the comments, through the Extended API's history endpoint, so Redmine's "Last updated
  by" is the last person who acted in Jira. A value Redmine does not have is left out and the chain stays connected;
  such an issue gets `jira-history.json`. Its log line `History: ... issue(s), ... journal(s) ...` gives the counts;
  `[note]` lines name the open parents (their history ends as in Jira, the issue has "Resolved"), a `[warn]` line a
  history that ends in another status. A rerun sends only what is missing. `--dry-run` writes the requests to
  `history-preview.jsonl` in the attachment directory (Jira data: never in the repository).
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

`J2R_STEP=restore` (or by hand): remove the opened workflow transitions, remove the role
`Migration (temporary)` and the memberships left without a role, lock the activated users again;
check the group memberships; switch prune, LDAP sync and the checklists setting back on and stealth mode off.

- Watchers and assignees stay as they were in Jira: removing a member role or membership prunes no
  watcher in Redmine 5.1 or 7.0, and Redmine checks an assignee only when it changes. A watcher who
  is no member any more gets no notifications; an issue whose assignee lost access can still be edited.
- **Never run `rake redmine:watchers:prune` after the migration**: it deletes every watcher without
  access to the issue, the historical watchers the migration kept on purpose.

### Apply the workflows (Jan, decision 13)

```bash
php tools/workflow_proposal.php            # writes the three review tables and a local report
# review, then:
php tools/workflow_plan.php --out=/path/plan.json
# in the Redmine root (needs redmine_custom_workflows for the scripts):
bundle exec rails runner /path/tools/apply_workflows.rb /path/plan.json                 # preview
bundle exec rails runner /path/tools/apply_workflows.rb /path/plan.json --confirm-push  # apply
```

- Mode (`migration.workflows.mode`): `project` (Jan, 2026-10-14) needs redmine_project_workflows and gives
  each project exactly its own Jira workflow; the union (rows with `redmine_project_id` 0, cleaned by Jan)
  is the global workflow for projects without their own, but only of the trackers 07 created: a tracker matched
  to one Redmine already had keeps its global workflow (Jan, decision 34), and `workflow_plan.php` names it. `union` writes only the global workflow. On the
  plugin's 7.0 branch the runner makes the plugin's scopes itself; on its 5.1 version a project role
  without transitions follows the global workflow (the plugin cannot store an empty one).
- Only PROPOSED rows are applied. A REVIEW row waits for a decision: set it to PROPOSED or IGNORED.
  A Jira condition Redmine cannot carry gives a REVIEW row, unless `migration.workflows.dropped_conditions`
  leaves it out for that project (Jan, decision 19: FPS's field value condition); the row is then PROPOSED.
  A row edited by hand keeps its edit when the proposal is made again.
- `migration_mapping_workflow_transitions`: per tracker and role, the runner sets exactly the PROPOSED
  transitions; a tracker and role whose rows are all IGNORED gets none. A row with role 0 is a transition
  Jira limits to a group that is not a member of the project: set `review_role_id` to the role that gets
  it and PROPOSED, or IGNORED. Jan's settings settle these per project and group (decision 14,
  `migration.workflows.group_roles`): `G` makes the group a member with its own role `<group> (workflow)`
  (04 proposes the membership; 04's push creates the role through the Extended API: Edit issues only, not
  assignable), `R` gives the transitions to the roles the group's users hold, `ignore` marks the rows
  IGNORED. Only a group not listed still gives review rows; run 04 and its push before the proposal.
  Trackers fed by several Jira workflows get their union (decision 13);
  Jan decided to keep the union as it is (decision 18): it stays the global workflow for projects without their
  own, "Create RMA" on Task included; he can still mark a union transition IGNORED later, or change it in
  Redmine's workflow screen. The report's "Union per tracker" lists per transition the Jira workflows and projects it
  comes from and how many issues sit in its from-status. One transition for every role:

  ```sql
  UPDATE migration_mapping_workflow_transitions SET migration_status = 'IGNORED'
  WHERE redmine_tracker_id = 29 AND redmine_old_status_id = 2 AND redmine_new_status_id = 14;
  ```
- A transition with a permission condition goes only to the roles that hold the permission in the
  project's Jira permission scheme (04 stages the holders); without them the rows are REVIEW.
- `migration_mapping_workflow_field_permissions`: a field a transition screen shows and Jira requires,
  as a Redmine field permission ("required" for the status the issue is saved with). GEOxyz's Jira has
  none: the only screen in use (Resolve Issue Screen: Solution, Resolution, Log Work) has no required
  field in any of the 12 field configurations, so no rule, as in Jira (Jan, 2026-10-14).
- `migration_mapping_workflow_scripts`: Jira's post function "assign to the current user", as a
  redmine_custom_workflows script per tracker and status (`j2r: ...`), limited to the projects whose
  Jira workflow has it. Apply it after the import: the script runs on every save.
- The runner replaces the temporary open workflows of its trackers and roles and takes them out of
  `restore`'s list, so the order of `restore` and the runner does not matter. A second run changes
  nothing. Statuses Jira has but no issue uses (9 at GEOxyz) are skipped.
- Check against Jira (test instances): `php tools/workflow_compare.php --sets=sets.json`, then
  `SETS=sets.json OUT=answers.json bundle exec rails runner tools/testenv/workflow_fingerprint.rb`, then
  `php tools/workflow_compare.php --answers=answers.json`: every difference must have a named category.

## Choices for Jan (plan work list 15, decided 2026-10-13)

| manual step today | options | recommendation |
|---|---|---|
| open workflows, reset after | (a) script it (done: `workflows` / `restore`); (b) Extended API import mode that skips the workflow and initial-status checks (session A) | **Jan: (a)** |
| activate locked users, lock after | (a) script it (done: `members` / `restore`); (b) Extended API import mode that accepts locked users as author and assignee | **Jan: (a)** |
| attachment prune off | (a) prune off during the run; (b) upload each attachment in 10 right before its issue is created, so nothing waits 24 h | **Jan: (a)**, runbook step, no code change |
| LDAP sync off, stealth mode on for the migration account (a safety net since every issue write goes through the Extended API) | runbook step | runbook step |

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
