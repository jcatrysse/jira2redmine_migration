# Production: Redmine 5.1 to 7.0, then the Jira migration

One document for the rehearsal and for production (Jan, decision 31 in docs/MIGRATION-PLAN.md). Part A
upgrades GEOxyz's Redmine 5.1 to 7.0; part B migrates Jira Cloud into that Redmine 7.0, in four batches;
part C is the prompt for the local Claude Code session that runs both on the server. No hostnames,
addresses or secrets in this file: the repository is public. Placeholders are written `<like-this>`.

## Samenvatting (Nederlands)

- **Volgorde:** eerst de bestaande Redmine 5.1 naar 7.0 (deel A) en controleren, daarna Jira rechtstreeks in die
  7.0 (deel B), in vier batches: WIKI; GEOS, SIS, OAC; SURV, DOC, LABO; ICT, LLE, SOIL, SSPT, ROV, GSPP, FPS. TEST
  gaat niet mee.
- **Eerst een generale repetitie** op een kloon van de productie-VM: mail uit, bijlagen als steekproef, alle
  projecten, met relink, workflows en restore. Daarna productie: snapshot voor elke batch, alle bijlagen.
- **Nieuw risico: de bestaande Redmine.** Tot nu toe ging elke test naar een lege Redmine. De scripts zoeken eerst
  een bestaand project, gebruiker, groep, status, prioriteit, tracker of veld, en dat op naam of identifier. Een
  verkeerde match zet Jira-data in een bestaand object, een gemiste match maakt een dubbel. Jan bekijkt in de
  eerste batch elke match voor de push (B6, met de queries).
- **Wat bestaande objecten wijzigt:** 03 voegt Jira-leden toe aan een bestaande groep met dezelfde naam, 07 en 08
  voegen trackers en projecten toe aan bestaande trackers en velden, 04 voegt rollen toe aan bestaande
  lidmaatschappen. De `workflows`-stap opent tijdelijk alle transities voor Manager en Developer op alle trackers,
  de workflow-runner vervangt de globale workflow van de gebruikte trackers.
- **Gevonden tijdens het schrijven:** B73, nieuwe prioriteiten komen onderaan de rangorde (Blocker werd de laagste).
  En: in productie moet stealth mode aan voor het migratie-account, want 13 (tags) en de relink sturen anders mail.
- **Open voor Jan:** de rolnamen Manager en Developer, de globale workflow op bestaande trackers, en B73 (B6).
- **Duur:** WIKI ongeveer 1 uur, GEOS, SIS en OAC 40 minuten, SURV 80 minuten met steekproef. In productie komen
  daar voor SURV 2,5 tot 4 uur bijlagen bij. De negen projecten die nog nooit liepen meet de repetitie.
- **Deel C** is de prompt voor de lokale Claude-sessie op de server: Jira alleen lezen, standaard rechten, Jans go
  voor elke schrijfstap in productie, rapport in het Nederlands.

## How to use this document

- **Order (decision 29):** first part A (upgrade 5.1 to 7.0, then check it), then part B (Jira into that 7.0).
  Redmine 5.1 stays a supported target of the scripts, but production goes to 7.0.
- **Two runs of the same procedure (decision 30):** the **rehearsal** on a clone of the production VM
  (outgoing mail off, attachments sampled), then **production**. Section B1 lists what differs.
- **Who decides:** Jan gives the go at every review point (marked **GO**). In production he also gives the go
  before every write (`--confirm-push`, `--confirm-pull`, a `rails runner` step that changes Redmine).
- **Where it runs:** a local Claude Code session on the server runs the commands (part C), step by step,
  logs every step and reports in Dutch after each batch.
- **Branch (decision 32):** the rehearsal runs from `redmine70-migration`; production runs from `main`, once
  the branches are on main and checked identical to what the rehearsal ran.

The detailed background of every step (why it is there, what was measured) is in docs/RUNBOOK.md and in
docs/MIGRATION-PLAN.md (Jan's decisions, findings B1 to B72). This document is the procedure.

<!-- Part A is written by the coordinator session and included here as written. -->

## Part A: upgrade Redmine 5.1 (GEOxyz) to 7.0 (GEOxyz)

Written by the coordinator from the upgrade rehearsal of 2026-10-14/15 and the "After the upgrade
(production)" section of each plugin's `docs/REDMINE7-MIGRATION.md` (branch `redmine70-migration`). Jan's
order (2026-10-15): upgrade first, check, then migrate Jira into 7.0 (Part B).

### A.0 What was proven, and what this run proves for the first time

Proven in the cloud test environment: a 5.1-stable-GEOxyz database with the 44 production plugins and
seeded data (not production data), upgraded in place to 7.0-stable-GEOxyz `71b9479` with the 44 plugins on
`redmine70-migration`. `db:migrate` and `redmine:plugins:migrate` exit 0 with no manual workaround; the schema
equals a fresh 7.0 install apart from column details without effect (datetime precision); row counts equal;
the workflow answers of 35 sample transitions identical before and after; 900 pages crawled as admin and as a
manager, 0 server errors (5.1 itself had 16); 13 of 13 write actions pass. Each plugin's suite is green with
all 44 installed.

Not proven anywhere yet, so the rehearsal on the clone checks these first:
- the real production database and files (sizes, old data, plugin data, saved queries, custom SQL, formulas,
  view_customize snippets, custom workflow scripts);
- plugins installed in production that are not in the list in A.3 (stop and report one);
- the four RedmineUP plugins that do not move to 7.0 (redmine_drive, redmine_questions, redmine_reporter,
  redmine_resources, decision 25): their tables stay in the database, untouched and unused;
- the real Active Directory (redmine_ldap_sync), the real identity provider (bless-this-redmine-sso),
  real mail in and out, the web server configuration.

### A.1 The clone: switch off everything that reaches the outside world

A clone of the production VM carries production's configuration. Before its Redmine or cron starts:

| what | why | how |
|---|---|---|
| incoming mail (helpdesk mailboxes, `redmine:email:receive_*`) | the clone would fetch, and delete or mark read, production's real mail | disable the clone's crontab before boot (`crontab -r` for the Redmine user, after saving it with `crontab -l`) |
| outgoing mail | users would get mails from the clone | `config/configuration.yml`: `delivery_method: :file` (mails land in `tmp/mails`) |
| webhooks | 5.1's webhooks are carried over to 7.0 and fire to real endpoints | after the upgrade, before the start: `UPDATE webhooks SET active = false;` |
| AI calls (redmine_ai_triage, redmine_ai_summary) | they send issue text to the provider and change issues | leave their cron off; do not open AI actions on the clone |
| scheduled reports (redmine_reporter_dashboards) | they mail | cron off (covered by the crontab step) |
| LDAP sync | reads the AD, writes only to the clone: allowed | run it by hand only (A.5) |
| SSO | the identity provider redirects to production's address | test SSO on production after the go, or with a test redirect URI at the IdP |
| host name | links and mails must not point at production | Administration > Settings > General > Host name: the clone's name |

### A.2 Prerequisites on the server

- **Ruby 3.3** (tested 3.3.6; core accepts 3.2, not tested here), Bundler. Rails 8.1.4 comes with the bundle.
- **Database**: as production today (the core migrations were rehearsed on PostgreSQL 16 and MariaDB). The
  Jira migration's staging MariaDB of Part B is a separate, temporary database.
- **Redmine runs as an unprivileged user** (Passenger or Puma). redmine_reporter_dashboards renders PDF with
  headless Chromium, which refuses to run as root.
- **Tools on the server**: `pandoc` (core Office preview, more_previews "mark"), LibreOffice `soffice`
  (more_previews "libre"); ImageMagick and Ghostscript only if more_previews "maggie" stays on (it is off after
  the hand-over task in A.5). Chromium for reporter_dashboards PDF.
- **Web server**: https; WebSocket upgrade on `/rup_cable` (redmine_zenedit's editing indicator, redmine_agile
  live boards), e.g. nginx `proxy_http_version 1.1; proxy_set_header Upgrade $http_upgrade; proxy_set_header
  Connection $connection_upgrade; proxy_set_header X-Forwarded-Proto https;`. With a Content-Security-Policy:
  `script-src` must allow the mermaid host (default `cdn.jsdelivr.net`). Large attachments: the Apache appendix
  of the runbook.
- **`config/database.yml` without ERB**: the 5.1 GEOxyz change that allowed ERB there during `bundle install`
  was not carried to 7.0 (Jan deferred it). If production's file uses `<%= %>`, write the values in plainly
  for the upgrade.

### A.3 What to deploy

Core: `jcatrysse/redmine`, branch `7.0-stable-GEOxyz` (rehearsal: the commit named in the report; production:
the same commit). Plugins: one directory per row; **the directory name matters** (it is the plugin id stored in
the database). Rehearsal: branch `redmine70-migration`; production: `main`/`master` after Jan's merge
(decision of 2026-10-15), whose tree the coordinator checks is identical to the tested one.

| repository (jcatrysse/...) | directory under `plugins/` |
|---|---|
| bless-this-redmine-sso | `bless_this_redmine_sso` |
| calendar_events_daily | `calendar_events_daily` |
| custom_field_sql | `custom_field_sql` |
| issue_recurring | `issue_recurring` |
| redmine-view-customize | `view_customize` |
| redmine_agile | `redmine_agile` |
| redmine_ai_summary | `redmine_ai_summary` |
| redmine_ai_triage | `redmine_ai_triage` |
| redmine_checklists | `redmine_checklists` |
| redmine_contacts | `redmine_contacts` |
| redmine_contacts_helpdesk | `redmine_contacts_helpdesk` |
| redmine_context_menu_actions | `redmine_context_menu_actions` |
| redmine_custom_workflows | `redmine_custom_workflows` |
| redmine_depending_custom_fields | `redmine_depending_custom_fields` |
| redmine_description_macros | `redmine_description_macros` |
| redmine_drawio | `redmine_drawio` |
| redmine_editauthor | `redmine_editauthor` |
| redmine_extended_api | `redmine_extended_api` |
| redmine_impersonate | `redmine_impersonate` |
| redmine_inline_edit_issues | `redmine_inline_edit_issues` |
| redmine_issue_field_visibility | `redmine_issue_field_visibility` |
| redmine_issue_templates | `redmine_issue_templates` |
| redmine_issue_todo_lists2 | `redmine_issue_todo_lists2` |
| redmine_issue_view_columns | `redmine_issue_view_columns` |
| redmine_itil_priority | `redmine_itil_priority` |
| redmine_ldap_sync | `redmine_ldap_sync` |
| redmine_mail_digest | `redmine_mail_digest` |
| redmine_mermaid_macro | `redmine_mermaid_macro` |
| redmine_more_previews | `redmine_more_previews` |
| redmine_paste_as_wiki_tables | `redmine_paste_as_wiki_tables` |
| redmine_parent_child_filters | `redmine_parent_child_filters` |
| redmine_people | `redmine_people` |
| redmine_plugin_computed_custom_field | `computed_custom_field` |
| redmine_project_workflows | `redmine_project_workflows` |
| redmine_reporter_dashboards | `redmine_reporter_dashboards` |
| redmine_stealth | `redmine_stealth` |
| redmine_subtask | `redmine_subtask` |
| redmine_tags | `redmineup_tags` |
| redmine_tint_issues | `redmine_tint_issues` |
| redmine_user_specific_theme | `redmine_user_specific_theme` (only until the theme switch in A.4 step 9) |
| redmine_view_issue_description | `redmine_view_issue_description` |
| redmine_wiki_extensions | `redmine_wiki_extensions` |
| redmine_zenedit | `redmine_zenedit` |
| that_attachments_limit | `that_attachments_limit` |

Plus `haru/redmine_theme_changer`, tag `0.7.1`, directory `redmine_theme_changer` (replaces
redmine_user_specific_theme, A.4 step 9). Not deployed: redmine_drive, redmine_questions, redmine_reporter,
redmine_resources (decision 25: with them every issue mail fails on 7.0). If anyone still needs their data
(Drive files, questions), export it on 5.1 first; nothing in the upgrade deletes it.

Keep from the 5.1 installation: `config/database.yml`, `config/configuration.yml`,
`config/additional_environment.rb`, the secret (`config/initializers/secret_token.rb` or `secret_key_base`),
`files/` (or its configured path), `plugins/redmine_issue_view_columns/config/redmine_issue_view_columns.local.rb`
and its locale file (the GEOxyz relation types), and the themes you keep (A.4 step 4).

### A.4 The upgrade, step by step

Before (on 5.1, read-only; save every output outside the repositories, it holds production data):

1. Inventory: `ls plugins` (compare with A.3), `crontab -l` of the Redmine user, `ls public/themes`, the themes
   users chose (`SELECT u.login, p.others FROM user_preferences p JOIN users u ON u.id = p.user_id WHERE
   p.others LIKE '%ui_theme%';`) and the global one (`SELECT value FROM settings WHERE name = 'ui_theme';`),
   the webhooks (`SELECT id, url, active FROM webhooks;`).
2. The checks that are compared after the upgrade: the view_issue_description roles (the command in A.5,
   row 1), the redmine_project_workflows backup with the old release
   (`bundle exec rake redmine_project_workflows:backup FILE=<outside>/pw-before.json RAILS_ENV=production`),
   the helpdesk mail rules (`SELECT id, name, mail_type, position, conditions, actions FROM helpdesk_mail_rules
   ORDER BY position;`), the stored SQL fields (`SELECT id, name, field_format, format_store FROM custom_fields
   WHERE field_format IN ('sql','sql_search');`), the digest rules.
3. Production only: announce the window, stop the web server and the crontab, then the snapshot (VM) and a
   database dump plus `files/`.

The upgrade (rehearsal and production alike):

4. Put the new tree in place (A.3), with the kept files. Move every theme you keep from `public/themes/<id>` to
   `themes/<id>` before the first boot, same directory name; Opale from its 1.7.2 release zip into
   `themes/opale`; PurpleMine2 is not copied (its users go to Opale in step 9).
5. `bundle config set without 'development test'`, then `bundle install`.
6. `RAILS_ENV=production bundle exec rake db:migrate`. On a 5.1-stable-GEOxyz database this also sets the 5.1
   webhook tables aside and copies their rows into 7.0's (`8dc5e73`) and carries and then removes the renamed
   5.1 settings (`71b9479`). Copied webhooks are not validated: one whose host does not resolve keeps firing
   (and failing) and can only be saved again once its URL is fixed.
7. `RAILS_ENV=production bundle exec rake redmine:plugins:migrate`. New on GEOxyz data: mail_digest 011 (if 5.1
   does not have it yet) and 012 (012 converts the 5.1 rules from YAML to JSON: required), checklists 008
   (subject to text), tags 004 and 005 (tags.color, cache rebuild), zenedit 003 (viewings.ip), people 021 (moves
   the announcement department, drops a column: the dump of step 3 is the rollback), project_workflows 004 to
   007, itil_priority 003 and 004, view_customize 009, ai_triage `20261014150000`, issue_view_columns 003,
   wiki_extensions (the renamed 0015), helpdesk 013 to 015.
8. **Right after**: `RAILS_ENV=production bundle exec rake redmine:plugins:helpdesk:fix_renamed_queries`
   (saved queries with the old customer filters and columns; without it they silently lose them).
9. Themes, as redmine_user_specific_theme's document describes: put `redmine_theme_changer` 0.7.1 in place,
   `redmine:plugins:migrate` again, then
   `RAILS_ENV=production bundle exec rake redmine_user_specific_theme:convert_to_theme_changer THEME_MAP=<old>:opale DRY_RUN=1`
   (use the theme id exactly as stored, e.g. `PurpleMine2:opale`; read the `skipped:` lines), the same without
   `DRY_RUN`, and a third time (only `unchanged`). Then remove `plugins/redmine_user_specific_theme`. If the
   global theme was PurpleMine2: Administration > Settings > Display > Theme = Opale.
10. Assets and caches: `rm -rf public/assets`, `RAILS_ENV=production bundle exec rake assets:precompile`,
    `rm -rf tmp/more_previews/*`, `rm -rf public/plugin_assets/calendar_events_daily`.
11. Rehearsal only: `UPDATE webhooks SET active = false;` (A.1). Then start Redmine as its user.

### A.5 After the upgrade, before users log in

Run as the Redmine user in the Redmine root, `RAILS_ENV=production`. Expected result in the last column; anything
else is a finding.

| # | what | command or place | expected |
|---|---|---|---|
| 1 | view_issue_description roles, compared with step 2 | `bundle exec rails runner 'Role.sorted.each { \|r\| next unless r.has_permission?(:view_issues); t = r.permissions_all_trackers?(:view_issue_description) ? "all" : r.permissions_tracker_ids(:view_issue_description).join(","); puts [r.name, r.has_permission?(:view_issue_description) ? "view_issue_description: #{t}" : "NO view_issue_description", r.has_permission?(:view_watched_issues) ? "view_watched_issues" : "-"].join(" \| ") }'` | identical to before |
| 2 | ITIL priority (finding F9) | Administration > Roles: remove "Override ITIL priority" from the helpdesk staff roles; **tick it on Anonymous** | a test mail to a helpdesk mailbox (production, after the go) gets the helpdesk's default priority |
| 3 | project workflows (finding F8) | Administration > Roles: grant "View the project's workflow" and "Manage the project's workflow" (redmine_project_workflows) to the roles that had the old project workflow permissions; `bundle exec rake redmine_project_workflows:backup FILE=<outside>/pw-after.json`; once `rake redmine_project_workflows:deduplicate_workflow_rules` | the same rules as `pw-before.json`; Workflow > Fields permissions shows Impact and Urgency |
| 4 | more_previews hand-over (Jan q3) | `DRY_RUN=1 bundle exec rake redmine_more_previews:use_core_previews`, then without, then again | the last run: "Nothing to change" |
| 5 | computed custom fields | `bundle exec rake redmine:computed_custom_field:check_formulas` | exit 0, no `FAIL` (fix a FAIL in Administration > Custom fields) |
| 6 | custom workflow scripts | `bundle exec rake redmine:custom_workflows:audit` | no script reported |
| 7 | view_customize | `bundle exec rails runner plugins/view_customize/docs/audit_snippets.rb`; scripts that read the API key in an unusual way get "use API key" ticked by hand | snippets fixed only where broken (Jan) |
| 8 | stored SQL fields | run every statement of step 2 once on 7.0; `%<name>d` or `%s` placeholders become `%{name}` | each statement runs; the new issue form opens |
| 9 | digest rules | `bundle exec rails runner 'IssueDigestRule.find_each { \|r\| puts [r.id, r.valid?].inspect }'`; rules with timezone `Etc/GMT+12` corrected in the form | every rule `true` |
| 10 | tags | `bundle exec rails runner 'p Issue.where.not(cached_tag_names: [nil, ""]).count'` | the number of tagged issues; tag filters and the issue report open |
| 11 | checklists | Administration > Roles: `done_checklists` for the roles that tick items | as before |
| 12 | issue view columns | `bundle exec rails runner 'p Setting.related_issues_default_columns'`; Settings > Issue tracking > "Show table headers" on if the captions are wanted | the plugin's global columns |
| 13 | small clean-ups | `DELETE FROM wiki_extensions_menus WHERE menu_no NOT BETWEEN 1 AND 5;` and, optional, `UPDATE agile_data SET agile_sprint_id = NULL WHERE agile_sprint_id = 0;` | |
| 14 | settings that changed meaning | Plugins > Redmine Edit Issue Author: "Possible authors" (new default: project members who may add issues); Roles: HR roles "Users visibility" = all active users (redmine_people); helpdesk "Add contact notes" off per confidential project (helpdesk document, q7); mail_digest "Allow email-address recipient lookup" if rules use addresses | as decided |
| 15 | Project > Settings, every tab | as admin and as a project manager; `grep -rnE "^\s*alias_method.*project_settings_tabs" plugins/` prints nothing | no error page |
| 16 | REST API and text formatting | Administration > Settings > Integrations (REST on); General > Text formatting = CommonMark | on, CommonMark |
| 17 | LDAP sync (real AD) | `DRY_RUN=1 bundle exec rake redmine:plugins:ldap_sync:sync_all`, read it (the first run creates groups and memberships that 5.1 never synced), then the real run; log in as one account in the required group, one outside, one disabled in AD | as the AD says |
| 18 | SSO | `bundle exec rake redmine:bless_this_sso:status`; the entry point is now `/oauth/sso/authorize` (callback unchanged) | production: login, SSO-only, sign out |
| 19 | the whole site | open the issue list, an issue, the agile board, the calendar, the gantt, My page, a wiki page, a helpdesk ticket, a contact, the people page, as admin and as a normal user; on the clone Claude can crawl every menu link and report any 500 | no error page |

### A.6 Cron entries

Re-create every entry of the saved 5.1 crontab for the new path, with `bundle exec` and the new Ruby on `PATH`
(`rake` alone is not found with rbenv). The plugins name theirs: issue_recurring
(`redmine:issue_recurring:renew_all`, daily), mail_digest (`redmine:issue_digest:send` every 5 to 15 minutes,
`redmine:issue_digest:cleanup` daily), ldap_sync (`redmine:plugins:ldap_sync:sync_all`), reporter_dashboards
(`reporter_dashboards:schedules:run`), more_previews (`redmine_more_previews:cache:clean DAYS=30`, daily, new),
and the mail fetching and AI triage entries production already has. On the clone the crontab stays off.

### A.7 Rollback

The rollback is the snapshot of step 3, not the migrations (the core steps that take over the 5.1 webhook tables
and settings are one way): stop Redmine, restore the VM snapshot (or the database dump, `files/` and the 5.1
tree), restart 5.1 and its crontab. Decide go or rollback before users work in 7.0: after that, a rollback loses
their changes.

### A.8 Time

The rehearsal's database upgrade took minutes on seeded data; production's duration is what the clone
measures (the people, checklists and tags migrations and the asset compile are the longest steps). Record it
in the rehearsal report: it sets the maintenance window.

## Part B: The Jira migration into Redmine 7.0

### B1. Two modes: rehearsal and production

| | rehearsal (decision 30) | production |
|---|---|---|
| machine | a clone of the production VM, upgraded to 7.0 by part A | the production server, upgraded by part A and checked |
| outgoing mail, webhooks, cron | off on the clone (A.1: crontab off, mail to files, webhooks inactive); that covers part B too | on for the users; the migration account in stealth mode, the webhooks inactive during part B (B5) |
| attachments | `attachments.test_sample = true`: every file up to 512 KB plus the largest per extension up to 20 MB; the rest is noted "skipped by sample" | `test_sample = false`: every file |
| snapshot | A.4 step 3 is the upgrade's rollback point; part B's own is a second snapshot after A.5 | the same two, and **one before every batch** (B10); no batch starts without one |
| GO | at the review points (B6, B7 steps 2, 4 and 12, B9) | at the review points and before every write |
| Jira | the live Jira, read-only (B4); the data is what Jira holds that day | the same, read-only |
| afterwards | the clone is thrown away; findings go to Jan | Redmine stays; staging is removed (B9) |

Everything else is the same: the batches, the commands, the checks. The rehearsal is there to find what an
existing Redmine does to the matching (B6) and to run the nine projects that never ran (DOC, LABO, ICT, LLE,
SOIL, SSPT, ROV, GSPP, FPS).

### B2. Prerequisites on the server

- **The scripts** run on any machine that reaches Redmine's REST API and Jira over HTTPS; the simplest is the
  Redmine server itself. **PHP 8.2 or newer (CLI)** with pdo, pdo_mysql, mbstring, ctype, json, dom, libxml,
  curl and openssl (Debian/Ubuntu: `apt install php8.2-cli php8.2-mysql php8.2-mbstring php8.2-xml
  php8.2-curl`), and **composer**. Every script checks this first and stops with `[ERROR] This PHP cannot run
  the migration scripts:` and what is missing.
- **The `rails runner` steps** (`tools/testenv/manual_steps.rb`, `tools/apply_workflows.rb`) run **on the Redmine
  server, in the Redmine root**, as the user Redmine runs as, with `RAILS_ENV=production`. They need a checkout
  of this repository readable by that user. `manual_steps.rb` keeps its list of opened workflow transitions in
  `<redmine-root>/tmp/j2r_added_workflows.json` until `restore`: **do not clear Redmine's tmp directory**
  (`rake tmp:clear`) between the first batch and `restore`.
- **A temporary MariaDB or MySQL 8** for the staging database, on the same VM so that one VM snapshot holds
  Redmine and staging together (B10). Install and create it as in docs/RUNBOOK.md, Prerequisites 2:

  ```bash
  apt install mariadb-server
  mysql -e "CREATE DATABASE jira2redmine CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER 'jira2redmine'@'localhost' IDENTIFIED BY '<staging-password>';
    GRANT ALL ON jira2redmine.* TO 'jira2redmine'@'localhost';"
  mysql jira2redmine < database/schema.sql
  ```
- **Disk.** `paths.tmp` holds the downloaded attachments; 09 does not delete them after the upload, so it grows
  with every batch until it is emptied (B7 step 14). Redmine's `files/` grows by the same amount. Measured: GEOS,
  SIS, OAC 1.6 GB; WIKI 4.7 GB; SURV 98.7 GB in Jira (0.98 GB with the sample). Plan for production: the SURV
  batch alone needs about **100 GB free in `paths.tmp` and 100 GB in Redmine's `files/`**, plus DOC and LABO
  (sizes in B8). The staging database stays small next to that (a few GB: the Jira payloads of every issue and
  comment).
- **tmux** for the long steps (B8), so a dropped SSH session does not stop a step.
- **Redmine 7.0** after part A, with the plugins the migration uses: `redmine_extended_api` (issues, journals,
  relations without mail and with Jira's authors and dates), `redmine_project_workflows` (each project its own
  workflow, decision 13), `redmine_custom_workflows` (the "assign to me" scripts), `redmine_tags`,
  `redmine_checklists`, `redmine_view_issue_description`, `redmine_stealth`, and the GEOxyz relation types in
  `redmine_issue_view_columns` (B5).
- **The web server** (docs/RUNBOOK.md, appendix): `LimitRequestBody` above the largest attachment (Jira's largest
  is 1.8 GB; Apache's default since 2.4.53 refuses anything above 1 GiB with 413), `TimeOut 600`, X-Sendfile,
  `SetEnv RACK_QUERY_PARSER_PARAMS_LIMIT 12000`; Redmine's `attachment_max_size` above the largest attachment.

### B3. Configuration

A checkout of the repository (rehearsal: branch `redmine70-migration`; production: `main`), then
`composer install --no-dev`. `config/config.local.php` is not in git; this version reads every secret from the
environment, so none is written to a file:

```php
<?php
// config/config.local.php (not in git). Secrets come from the environment; never write them here.
$projects = array_values(array_filter(array_map(
    static fn (string $key): string => strtoupper(trim($key)),
    explode(',', (string)getenv('J2R_PROJECTS'))
)));

return [
    'jira' => [
        'base_url' => 'https://' . getenv('JIRA_SITE'),
        'username' => getenv('JIRA_EMAIL'),
        'api_token' => getenv('JIRA_API_TOKEN'),
        'cloud_id' => getenv('JIRA_CLOUD_ID'),
    ],
    'redmine' => [
        'base_url' => getenv('REDMINE_URL'),
        'api_key' => getenv('REDMINE_API_KEY'),
        'extended_api' => ['enabled' => true, 'prefix' => '/extended_api'],
    ],
    'database' => [
        'dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=jira2redmine;charset=utf8mb4',
        'username' => 'jira2redmine',
        'password' => getenv('J2R_STAGING_PASSWORD'),
    ],
    'paths' => ['tmp' => getenv('J2R_TMP')],
    'migration' => [
        'users' => [
            // the LDAP authentication source of the existing Redmine for new accounts (Administration, LDAP
            // authentication: the id in the link); null keeps password login
            'auth_source_id' => null,
        ],
        'issues' => [
            'projects' => $projects,
            // content of Jira app accounts and of people without a visible e-mail (B6): an existing account
            'default_redmine_author_id' => (int)getenv('J2R_DEFAULT_AUTHOR_ID'),
        ],
        'journals' => ['default_redmine_author_id' => (int)getenv('J2R_DEFAULT_AUTHOR_ID')],
    ],
    'attachments' => [
        'download_concurrency' => 6,
        'test_sample' => getenv('J2R_TEST_SAMPLE') === '1',
    ],
];
```

The environment, in a file only the operator can read (`chmod 600`), outside the checkout, sourced in every shell
and tmux window: `JIRA_SITE` (`<site>.atlassian.net`), `JIRA_EMAIL`, `JIRA_API_TOKEN`, `JIRA_CLOUD_ID` (from
`https://<site>.atlassian.net/_edge/tenant_info`), `REDMINE_URL`, `REDMINE_API_KEY` (an admin's API key: the
migration account), `J2R_STAGING_PASSWORD`, `J2R_TMP` (the attachment directory), `J2R_DEFAULT_AUTHOR_ID`,
`J2R_PROJECTS` (per batch, lowercase, B8), `J2R_TEST_SAMPLE` (`1` in the rehearsal only). Never print them, never paste
them in a chat, never commit them.

Everything else keeps the defaults of `config/config.default.php`, which hold Jan's decisions: TEST not
migrated (22), the service desk fields not migrated (20), the role mappings and B31 proposals (10), the
workflow mode `project` and the group settings (13, 14, 19), the comment attachment window 0 (27), the
GEOxyz relation types. Check that the local file loads, without printing a secret:
`php -r '$c = require "bootstrap.php"; echo implode(",", $c["migration"]["issues"]["projects"]), " | ", implode(",", $c["migration"]["projects"]["not_migrated"]), PHP_EOL;'`
prints the batch's projects and `TEST`.

### B4. Jira token: prove it read-only

Before the first Jira call of every session (decision 1). The token is a scoped API token with read scopes only
(the list is in docs/RUNBOOK.md, section 0). The write probe goes to an issue that does not exist, through the
gateway; the answer must be **401 "scope does not match"**. A 404 means the token can write: stop, and ask Jan
for a read-only token.

```bash
curl -s -X PUT -u "$JIRA_EMAIL:$JIRA_API_TOKEN" -H 'Content-Type: application/json' -d '{"fields":{}}' \
  -w '\nHTTP %{http_code}\n' "https://api.atlassian.com/ex/jira/$JIRA_CLOUD_ID/rest/api/3/issue/NONEXIST-1"
```

After that the scripts send only GET requests to Jira (and the same 401 refuses anything else).

### B5. Before the first batch

Once, on the upgraded Redmine 7.0. Each line is a check the local session reports.

| what | how | check |
|---|---|---|
| snapshot | the second snapshot, after A.5: part B's rollback point (A.4 step 3 is the upgrade's); production: also before every batch (B10) | the snapshot exists and its time is noted |
| REST API | Administration, Settings, API: REST web service on (A.5 row 16) | `curl -s -H "X-Redmine-API-Key: $REDMINE_API_KEY" "$REDMINE_URL/users/current.json"` gives the migration account, `admin: true` |
| Extended API | the plugin is installed | `curl -sI -H "X-Redmine-API-Key: $REDMINE_API_KEY" "$REDMINE_URL/extended_api/issues.json"` has the header `X-Redmine-Extended-API` |
| text formatting | Administration, Settings, General: CommonMark Markdown (A.5 row 16) | `Setting.text_formatting` is `common_mark` |
| no mail from the migration | rehearsal: outgoing mail is off on the clone. Production: stealth mode on for the migration account: `curl -s -X POST -H "X-Redmine-API-Key: $REDMINE_API_KEY" -d toggle=true "$REDMINE_URL/stealth/toggle.json"` (the account needs the permission "Toggle stealth mode"; it is an admin) | the answer is `{"is_cloaked":true}`. Signing in to the web interface as the migration account switches stealth off again: do not, or toggle it on after |
| relation types | the GEOxyz relation types of `migration.relations.custom_mappings` in `redmine_issue_view_columns` (`tools/testenv/redmine_issue_view_columns.local.rb` is generated from them) | after part A they exist in 7.0 (12 refuses a relation type Redmine does not know) |
| attachment prune off | the cron job or scheduled task that runs `rake redmine:attachments:prune` is disabled (uploads wait for their issue; prune deletes them after 24 h); on the clone the whole crontab is off (A.1) | the cron entry is commented out |
| LDAP sync off | the cron job of `redmine_ldap_sync` is disabled (it would lock or change accounts the migration activates); on the clone it runs by hand only (A.1) | the cron entry is commented out |
| webhooks | production: `UPDATE webhooks SET active = false;` for the duration of part B, after noting which were active (`SELECT id, url, active FROM webhooks;`). The Extended API steps fire none (notify=false), but 13 on 7.0 and the relink update issues through the plain API, and Redmine 7 fires the webhooks for those. Rehearsal: already inactive (A.1, A.4 step 11) | `SELECT COUNT(*) FROM webhooks WHERE active` is 0 |
| automation rules | Jan exports them once as JSON (docs/RUNBOOK.md, section 0); kept outside the repository | the file opens as JSON |
| staging | the empty staging database of B2 | `SHOW TABLES` lists the tables of `database/schema.sql` (81 today) |

The `workflows` step of `manual_steps.rb` (B7 step 3) also sets three global settings Jan decided, which stay after
the migration and apply to every project: parent priority independent (B13), cross-project subtasks "With all
projects" (B38), cross-project issue relations allowed (B39).

### B6. Matching against an existing Redmine (01 to 08): what Jan reviews

Every test run so far went into an empty Redmine. GEOxyz's Redmine has its own projects, users, groups, roles,
statuses, priorities, trackers and custom fields, and each of 01 to 08 first looks for an existing one and uses it
(`MATCH_FOUND`) instead of creating one. The match is on the identifier, the login or e-mail, or the **name only**,
without case, and without any check that the existing object means the same thing. A wrong match puts Jira data
into an existing object; a missed match creates a second one. This is the new risk of the rehearsal; in the first
batch Jan reviews every match before its push (**GO**), in later batches the new rows.

| script | matched on | what the push changes on an existing object | Jan reviews |
|---|---|---|---|
| 01 projects | the Jira key, lowercased, against the identifier of the **active** projects (closed and archived projects are not in Redmine's list) | nothing on the project itself; but the batch then puts the Jira issues into it, 07 adds trackers, 08 enables fields, 04 adds memberships | every `MATCH_FOUND`: is this the project the Jira issues belong in? A key that is the identifier of a closed or archived project fails with 422 "Identifier has already been taken" (`CREATION_FAILED`) |
| 02 users | the Jira e-mail against the Redmine login, then the primary e-mail; locked and registered accounts included | nothing; the `members` step activates a matched locked account for the run and `restore` locks it again | new accounts for a person who already has one under another e-mail (a second account), an e-mail that is only an additional address of an account (create fails "Email has already been taken"), logins Redmine refuses (a `+`, more than 60 characters) |
| 03 groups | the group name | **the Jira group's members are added to the existing group** (none removed): they get every role that group has in every project | every matched group; one that only shares a name: set its row `IGNORED` before the push |
| 04 roles and memberships | `migration.roles.mappings`, else the Jira role's own name | no role definition changes; a membership that exists gets the proposed role added (none removed); the `<group> (workflow)` roles of decision 14 are created, or reused when one has that name | the membership proposal (B7 step 4); on a project that existed before, a Non member row set READY opens it to every logged-in user |
| 05 statuses | the name | nothing; open or closed is **taken from Redmine**, not from Jira's category | matched statuses whose Jira category (`done`) and Redmine's closed flag disagree (listed first by the query) |
| 06 priorities | the name | nothing on matched ones; **new ones are inserted at position 1, 2, ... (B73, below)** | the new priorities and their position; map a Jira priority to an existing one where that is meant |
| 07 trackers | the name (shortened to 30 characters) | the tracker itself is not changed; each project gets the trackers it needs **added** to its tracker list | matched trackers that mean something else in GEOxyz's Redmine; new trackers get the lowest open status as default unless `migration.trackers.default_redmine_status_id` is set |
| 08 custom fields | the name (issue custom fields) | the migrated projects and trackers are **added** to the field (a field "for all projects" gets the trackers, so it shows on them in every project); a dependent field gets its value dependencies; the format, the visibility, the roles and the possible values stay as they are | every matched field: is its format the Jira type? 08 prints `[values] Redmine custom field #N existed before the migration and lacks ...` for Jira values the existing field does not have: add them by hand before 10 (Redmine refuses an issue with a value the field does not list) |

Review queries (staging, after the analysis of each script and before its push; tested on the 7.0 staging):

```sql
-- 01: a Jira key that is already a Redmine identifier, and what could not be created
SELECT jira_project_id, proposed_identifier, proposed_name, redmine_project_id, migration_status, notes
FROM migration_mapping_projects WHERE migration_status IN ('MATCH_FOUND', 'MANUAL_INTERVENTION_REQUIRED', 'CREATION_FAILED');

-- 02: matched accounts, and new accounts whose name an existing account already has (the same person?)
SELECT u.jira_display_name, u.jira_email_address, u.migration_status, r.id AS redmine_id, r.login, r.mail, r.status
FROM migration_mapping_users u
JOIN staging_redmine_users r ON r.id = u.redmine_user_id
   OR (u.migration_status = 'READY_FOR_CREATION' AND LOWER(CONCAT(r.firstname, ' ', r.lastname)) = LOWER(u.jira_display_name))
WHERE u.migration_status IN ('MATCH_FOUND', 'READY_FOR_CREATION')
ORDER BY u.migration_status, u.jira_display_name;

-- 03: existing groups that receive the Jira group's members
SELECT g.jira_group_name, g.redmine_group_id, g.migration_status,
  (SELECT COUNT(*) FROM migration_mapping_group_members m WHERE m.jira_group_id = g.jira_group_id) AS jira_members
FROM migration_mapping_groups g WHERE g.migration_status IN ('MATCH_FOUND', 'MANUAL_INTERVENTION_REQUIRED', 'CREATION_FAILED');

-- 04: the Jira roles
SELECT jira_role_name, proposed_redmine_role_name, redmine_role_id, migration_status, notes
FROM migration_mapping_roles WHERE migration_status <> 'IGNORED';

-- 05: statuses; a Jira "done" status that Redmine has open (or the reverse) first
SELECT jira_status_name, jira_status_category_key, redmine_status_id, proposed_redmine_name, proposed_is_closed, migration_status
FROM migration_mapping_statuses WHERE migration_status <> 'IGNORED'
ORDER BY (migration_status = 'MATCH_FOUND' AND (jira_status_category_key = 'done') <> (proposed_is_closed = 1)) DESC, jira_status_name;

-- 06: priorities and the position a new one gets
SELECT jira_priority_name, redmine_priority_id, proposed_redmine_name, proposed_redmine_position, proposed_is_default, migration_status
FROM migration_mapping_priorities;

-- 07: trackers; READY_FOR_UPDATE adds a tracker to projects
SELECT jira_issue_type_name, redmine_tracker_id, proposed_redmine_name, proposed_redmine_project_ids, migration_status, notes
FROM migration_mapping_trackers WHERE migration_status <> 'IGNORED' ORDER BY migration_status, jira_issue_type_name;

-- 08: fields matched to an existing Redmine field
SELECT jira_field_name, jira_schema_type, jira_schema_custom, redmine_custom_field_id, proposed_field_format,
       proposed_is_for_all, migration_status, notes
FROM migration_mapping_custom_fields WHERE migration_status IN ('MATCH_FOUND', 'READY_FOR_UPDATE', 'MANUAL_INTERVENTION_REQUIRED');
```

To undo a match, or to choose another object, edit the row before the push: `IGNORED` (not migrated), or
another `redmine_*_id` with `MATCH_FOUND`. The edit is kept on every rerun (the script prints `[preserved]`).
Mapping a MANUAL user row (no visible e-mail) to an existing account this way has not been run yet: try it in the
rehearsal before relying on it in production.

**Steps outside 01 to 08 that change existing Redmine objects:**

- `manual_steps.rb workflows` (B7 step 3) opens every transition for the roles named **Manager** and **Developer** on
  **every tracker** of the Redmine, as global rules: until `restore`, those roles can make any status change in
  every project that uses the global workflow, the existing projects included. `restore` removes exactly what it
  added. It also gives those two roles `view_watched_issues` and `view_issue_description` (they keep them) and sets
  the three settings of B5. If the GEOxyz roles have other names, it opens nothing and every issue lands in its
  tracker's default status (B12).
- `manual_steps.rb members` (B7 step 5) activates locked users and gives the temporary role: on an existing Redmine
  only with `J2R_USER_IDS_FILE`.
- `apply_workflows.rb` (B9 step 2) gives each migrated project its own workflow (mode `project`), and makes the
  cleaned union the **global** workflow of every tracker and role in the plan: the global transitions of those
  trackers and roles that are not in the union are removed. On an existing Redmine that changes the workflow of the
  existing projects that use a matched tracker (07) and have no workflow of their own. The preview counts it
  (`transitions +N -N`); on an empty Redmine the `-N` were only the temporary open workflows.

**B73 (defect, found while writing this section):** Redmine's priority list in the REST API has no position, so 06
counts no existing position and proposes 1, 2, 3 for the new ones; Redmine inserts them there and moves the
existing ones up. Position 1 is the lowest priority: on the 7.0 test instance the new "Blocker" became the
**lowest** priority, below "Low". Until it is fixed: before 06's push, set `proposed_redmine_position` of each new
row by hand (Administration, Enumerations shows the order; position 1 is the lowest), or map the Jira priority to
an existing one.

**Open for Jan** (through the coordinator; the rehearsal can start without the answers, production cannot):
(1) are the roles that create issues in GEOxyz's Redmine called Manager and Developer (`workflows` step)?
(2) the global union workflow on trackers existing projects use: write only each project's own workflow and leave
the global workflow of existing trackers alone (a small change, B2's advice), or accept and review the preview?
(3) B73: the fix, and where new priorities go relative to the existing ones.

### B7. One batch, step by step

Set `J2R_PROJECTS` to **every project migrated so far plus the batch's new ones** (B8; the jira phases of 07 and 08
read only the run's projects and replace their staging tables, so a batch with the new projects alone would narrow
what 08 knows of the earlier ones). Migrated issues, journals and attachments are skipped on the rerun.

Each script: first the analysis (`php NN_x.php`: jira, redmine and transform phases), read its summary
(`Matched / Ready / Manual`), then **`--phases=push --dry-run`** (what the push would send, without writing),
then **`--phases=push --confirm-push`**. In the commands below the dry-run is left out for brevity; in production
it comes before every push. Every step's output goes to a log, for example
`php 01_migrate_projects.php 2>&1 | tee "$LOG/01.log"` with `LOG=~/j2r-runs/<batch>` outside the checkout (the logs
hold Jira data: never in the repository).

1. **Snapshot** (production; B10).
2. **Structure: projects, users, groups, statuses, priorities, trackers.** Review the analysis before each push
   (B6). **GO** on the first batch: Jan reads B6's review list.

   ```bash
   php 01_migrate_projects.php && php 01_migrate_projects.php --phases=push --confirm-push
   php 02_migrate_users.php    && php 02_migrate_users.php    --phases=push --confirm-push
   php 03_migrate_groups.php   && php 03_migrate_groups.php   --phases=push --confirm-push
   # members wait for their group: a second pass once the groups exist (B32)
   php 03_migrate_groups.php --skip=jira && php 03_migrate_groups.php --phases=push --confirm-push
   php 05_migrate_statuses.php   && php 05_migrate_statuses.php   --phases=push --confirm-push
   php 06_migrate_priorities.php && php 06_migrate_priorities.php --phases=push --confirm-push
   php 07_migrate_trackers.php   && php 07_migrate_trackers.php   --phases=push --confirm-push
   ```
3. **Open the workflows** (on the Redmine server, in the Redmine root; B6 says what this touches):

   ```bash
   J2R_STEP=workflows RAILS_ENV=production bundle exec rails runner <checkout>/tools/testenv/manual_steps.rb
   ```

   Output: `workflow transitions created=N ...`; on a later batch N is 0 or small (new trackers only).
4. **Extract issues, attachments, watchers; propose the memberships.**

   ```bash
   php 08_migrate_custom_fields.php --phases=jira
   php 10_migrate_issues.php --phases=jira
   php 09_migrate_attachments.php --phases=jira
   php 14_migrate_watchers.php --phases=jira
   php 04_migrate_roles.php
   ```

   **GO: the memberships.** Jan reviews `migration_mapping_project_role_groups` (query in docs/RUNBOOK.md,
   "Review the proposed memberships"): every READY row becomes a Redmine membership that stays after the run. He
   sets the **Non member users** rows (application-role grants, GSPP and ROV) to `READY_FOR_ASSIGNMENT` or
   `IGNORED`: READY gives the role to every logged-in user who is not a member. His edits survive every rerun.
   Then:

   ```bash
   php 04_migrate_roles.php --phases=push --confirm-push
   ```
5. **Activate locked users and give the temporary migration role** (decision 12; Redmine server, Redmine root).
   `J2R_USER_IDS_FILE` is **required** on an existing Redmine: without it the step activates every user of the
   Redmine. The step finds the projects by identifier: the lowercase `J2R_PROJECTS` (the Jira key, lowercased, is
   the Redmine identifier, B6).

   ```bash
   mysql -N jira2redmine -e "SELECT redmine_user_id FROM migration_mapping_users WHERE redmine_user_id IS NOT NULL" > "$J2R_TMP/user_ids.txt"
   J2R_STEP=members J2R_USER_IDS_FILE="$J2R_TMP/user_ids.txt" \
     RAILS_ENV=production bundle exec rails runner <checkout>/tools/testenv/manual_steps.rb
   ```

   Output: `activated=N temporary memberships=N projects=N members=N`; `projects` must equal the number of projects in
   `J2R_PROJECTS`.
6. **Custom fields.** Review the analysis (B6: fields matched to existing Redmine fields), then push.

   ```bash
   php 08_migrate_custom_fields.php --phases=usage,redmine,transform
   php 08_migrate_custom_fields.php --phases=push --confirm-push
   ```

   The transform lists `[unoffered]` values (B37) to retire after the run (docs/RUNBOOK.md, section 2).
7. **Attachments** (long; tmux):

   ```bash
   php 09_migrate_attachments.php --phases=pull --confirm-pull
   php 09_migrate_attachments.php --phases=transform,push --confirm-push
   ```

   Check: `Jira attachment download summary — queued: N, downloaded: N, failed: 0`.
8. **Issues** (tmux). Twice: the second run writes descriptions and parents that waited for the first.

   ```bash
   php 10_migrate_issues.php --phases=transform,push --confirm-push
   php 10_migrate_issues.php --phases=transform,push --confirm-push
   ```

   Check: `[open-parent]` lines (a closed parent with an open subtask gets "Resolved", Jan's rule) and
   `Issues waiting for a parent outside the run (B38)`: every `[waiting]` line names a project of a later batch.
9. **History and links** (11 and 14 long; tmux):

   ```bash
   php 11_migrate_journals.php --confirm-push --use-extended-api
   php 12_migrate_issue_relations.php --confirm-push
   php 13_migrate_tags.php --confirm-push
   php 14_migrate_watchers.php --confirm-push
   php 15_migrate_checklists.php --confirm-push
   php 16_export_workflows.php
   ```

   On 7.0, 13 adds tags through the issue update: one journal "Tags" per tagged issue, by the migration account
   (B19); with stealth on, no mail.
10. **Counts** (the staging view of the batch):

    ```bash
    mysql jira2redmine -e "SELECT 'issues', migration_status, COUNT(*) FROM migration_mapping_issues GROUP BY 2
      UNION ALL SELECT 'journals', migration_status, COUNT(*) FROM migration_mapping_journals GROUP BY 2
      UNION ALL SELECT 'attachments', migration_status, COUNT(*) FROM migration_mapping_attachments GROUP BY 2
      UNION ALL SELECT 'relations', migration_status, COUNT(*) FROM migration_mapping_issue_relations GROUP BY 2
      UNION ALL SELECT 'watchers', migration_status, COUNT(*) FROM migration_mapping_watchers GROUP BY 2"
    ```

    Expected: issues CREATION_SUCCESS, plus READY_FOR_REWRITE only for a parent in a later batch; relations
    MANUAL only for an other side in a later batch ("Missing Redmine issue mapping"); no `FAILED` anywhere
    without a reason in `notes`; rehearsal: attachments PENDING_DOWNLOAD = the files skipped by the sample.
11. **Verify** (`--projects` = every project migrated so far):

    ```bash
    php tools/verify.php --projects="${J2R_PROJECTS^^}" --sample=25 --report="$LOG/verify.md"
    ```

    The last line must be **PASS**. The report holds issue keys and short Jira texts: it stays in `$LOG`.
12. **GO: Jan looks** at a few migrated issues of the batch in Redmine (description, comments, attachments,
    watchers, status) next to Jira, and gives the go for the next batch.
13. **Errors in the logs:** `grep -ciE '\[error\]|fatal|exception' "$LOG"/*.log` is 0 for every log, or each hit is
    explained in the batch report.
14. **Empty `paths.tmp`** once the batch's verify passed (09 uploaded every file; nothing later reads the local
    copies): `rm -rf "$J2R_TMP"/*` (keep `user_ids.txt` out of it, or make it again in the next batch).
15. **Report** (Dutch, short): counts, durations per step, failures and their reason, the review points and Jan's
    answers.

### B8. The four batches: projects, sizes and durations

The order is Jan's decision 22; `J2R_PROJECTS` grows with every batch:

| batch | new projects | `J2R_PROJECTS` |
|---|---|---|
| 1 | WIKI | `wiki` |
| 2 | GEOS, SIS, OAC | `wiki,geos,sis,oac` |
| 3 | SURV, DOC, LABO | `wiki,geos,sis,oac,surv,doc,labo` |
| 4 | ICT, LLE, SOIL, SSPT, ROV, GSPP, FPS | `wiki,geos,sis,oac,surv,doc,labo,ict,lle,soil,sspt,rov,gspp,fps` |

Lowercase, because `manual_steps.rb` finds the projects by their Redmine identifier; the configuration uppercases
the list for the scripts (B3).

TEST is not migrated (decision 22). The order puts first what others link to: GEOS issues hang under WIKI epics.

**Measured** (test instances, Redmine and staging on the same machine, `download_concurrency` 6; the logs per step
are in `runs/` of the test environment, not in this repository):

| run | issues | attachments | duration | longest steps |
|---|---|---|---|---|
| GEOS, SIS, OAC, first batch, 7.0 (5.1 the same) | 426 | 576, 1.6 GB, all | 24 min (25 on 5.1) | 11 8 min, 14 3 min, 09 push 2.4 min, 10 2.3 min |
| WIKI, second batch after GEOS, SIS, OAC, 7.0 | 1,457 (1,883 in the run) | 837, 4.7 GB, all | 76 min | 11 26 min, 14 13 min, 14 jira phase 8 min, 10 11 min, 09 7 min, 13 4 min |
| SURV alone, 5.1, with the sample | 1,494 | 4,306 of 6,323 sampled: 0.98 GB of 98.7 GB | 80 min | 11 43 min, 14 11 min, 09 12 min, 10 8 min |

Steps that grow with **every project migrated so far**, not only the batch's new ones: 14's jira phase reads the
watchers of every issue in `J2R_PROJECTS` (about 0.25 s per issue: 459 s for 1,883 issues), 16 reads every Jira
workflow (1.5 min), 08's transform (2 min). The other steps skip what is migrated.

**Attachments without the sample** (production): pull measured at 18 to 27 MB/s, upload at 11 to 18 MB/s into a
local Puma and 33 MB/s behind Apache with X-Sendfile (docs/RUNBOOK.md, appendix). SURV's 97.7 GB beyond the sample
then take about 1 to 1.5 hours to pull and 1 to 2.5 hours to upload, depending on the server's network.

**Estimates per batch** (rehearsal with the sample; production adds the attachment time):

| batch | rehearsal | production |
|---|---|---|
| 1: WIKI | about 1 hour | about 1 hour 15 min (4.7 GB) |
| 2: GEOS, SIS, OAC | about 40 min | about 40 min (1.6 GB) |
| 3: SURV, DOC, LABO | SURV 80 min plus DOC and LABO | plus 2.5 to 4 hours for SURV's attachments; DOC and LABO: see below |
| 4: ICT, LLE, SOIL, SSPT, ROV, GSPP, FPS | never ran | never ran |

DOC, LABO and the projects of batch 4 never ran: the rehearsal measures them (the duration per step is in each
batch report). Verify takes about 5 minutes (four projects, `--sample=25`, not in the durations above). After the
last batch, on the WIKI run on 7.0: the workflow proposal and plan a few seconds, the three runner passes, `restore`
and its proofs together under 2 minutes; the relink was not timed (13 texts on the test instance).

**In tmux:** the whole batch, or at least 09 (pull and push), 10, 11 and 14. A step that is interrupted can be run
again (B10).

### B9. After the last batch

1. **Relink** the links in text to issues a later batch migrated (B52), then the check:

   ```bash
   php tools/relink_issue_links.php                # preview
   php tools/relink_issue_links.php --confirm-push # rewrite (GO in production)
   php tools/relink_issue_links.php                # the check
   ```

   The check's last line, `Texts that still hold a Jira link to a migrated issue after this run`, must be **0**. A
   text listed as `[left]` was edited in Redmine since the migration: fix it by hand, run the check again. The
   relink writes through the issue update too: with stealth on, no mail. Also: 12's MANUAL relations and 10's
   `[waiting]` parents now name only projects that are not migrated (TEST).
2. **Workflows** (decision 13; B6 says what the runner changes on an existing Redmine):

   ```bash
   php tools/workflow_proposal.php --report="$LOG/workflow-proposal.md"
   # GO: Jan reviews the three review tables (docs/RUNBOOK.md, section 5): REVIEW rows to PROPOSED or IGNORED
   php tools/workflow_plan.php --out="$LOG/workflow-plan.json"
   # Redmine server, Redmine root:
   RAILS_ENV=production bundle exec rails runner <checkout>/tools/apply_workflows.rb "$LOG/workflow-plan.json"                 # preview
   RAILS_ENV=production bundle exec rails runner <checkout>/tools/apply_workflows.rb "$LOG/workflow-plan.json" --confirm-push  # GO
   RAILS_ENV=production bundle exec rails runner <checkout>/tools/apply_workflows.rb "$LOG/workflow-plan.json"                 # +0 -0
   ```

   The last preview must say `transitions +0 -0` and `waiting for review: transitions 0`.
3. **Restore** (Redmine server, Redmine root): takes away the workflow transitions the `workflows` step opened (not
   those the runner applied), the role `Migration (temporary)` and the memberships left without a role, and locks
   the activated users again.

   ```bash
   J2R_STEP=restore RAILS_ENV=production bundle exec rails runner <checkout>/tools/testenv/manual_steps.rb
   ```

   Output: `workflow transitions removed=N temporary member roles removed=N members=N users locked again=N`; `users
   locked again` equals the sum of `activated` of the four `members` steps. Then the workflow runner's preview once
   more: still `+0 -0`. Rehearsal: also `RAILS_ENV=production bundle exec rails runner
   <checkout>/tools/testenv/temporary_role_proof.rb` (with the batch 4 `J2R_PROJECTS`) (read-only: a rolled-back transaction) and `php
   tools/testenv/membership_check.php` (`memberships equal 04's proposal: yes`; on a project that existed before, its
   older memberships show as differences, B6).
4. **Settings back on** (production; on the clone the crontab stays off): stealth mode off for the migration account
   (`toggle=false`, answer `{"is_cloaked":false}`), the prune cron job and the LDAP sync cron job back on, the webhooks
   that were active before part B active again (B5). Leave cross-project subtasks and relations on (B5).
   **Never run `rake redmine:watchers:prune`** afterwards: it deletes the historical watchers the migration kept on
   purpose (decision 12).
5. **After the run, by hand:** retire the `[unoffered]` select values (docs/RUNBOOK.md, section 2); rebuild what the
   automation rules export lists (custom workflows, recurring tasks).
6. **Remove staging** once no rerun is needed: if a copy is wanted, `mysqldump jira2redmine` to a place outside the
   repository (it holds Jira data); then `mysql -e "DROP DATABASE jira2redmine; DROP USER 'jira2redmine'@'localhost';"`,
   `apt purge mariadb-server` if it was installed for this, empty `$J2R_TMP`, delete the environment file with the
   secrets, and ask Jan to revoke the Jira token.

### B10. When something fails: rerun or roll back

- **Rerun first.** Every script can run again: migrated rows are skipped, FAILED and MANUAL rows are judged again,
  rows edited by hand keep the edit (automation hash). Find the reason in the log and in the `notes` column of the
  mapping table, fix the cause (a missing relation type, a full disk, a network error), run the same step again,
  then the steps after it. Proved on 5.1 and 7.0: a second run of 08 to 15 changes nothing in Redmine.
- **A Jira error** (502, 503, 504, 429) is retried by the scripts (decisions 5 and B71) for reads; if a jira phase
  still stops, rerun it.
- **Roll back** (the upgrade itself: A.7) when a batch left Redmine in a state a rerun cannot repair (a wrong match pushed, B6; data in an
  existing project that should not be there): restore the VM snapshot taken before the batch. The snapshot must hold
  Redmine's database, Redmine's `files/` and `tmp/j2r_added_workflows.json`, **and the staging database**: staging
  records what was pushed, so a Redmine rolled back without its staging would make the next run skip issues that are
  no longer there. With staging on the same VM (B2) one snapshot holds all of it. Without a VM snapshot: a
  `pg_dump` of Redmine, a copy of `files/`, and a `mysqldump` of staging, taken at the same moment.
- **Do not hotfix the scripts on the server.** A defect: stop, write down the step, the log lines and the mapping
  rows, and report it (rehearsal: through Jan to the coordinator and B2; production: roll back and wait for a fixed
  `main`).

## Part C: Prompt for the local Claude Code session

Jan starts Claude Code on the server, in the checkout, in the **default permission mode**, and gives it this prompt
with the placeholders filled in. The environment file of B3 is in place; the session never sees its contents.

```text
On this server you upgrade GEOxyz's Redmine 5.1 to 7.0 (part A) and then migrate Jira Cloud into it
(part B), mode: <rehearsal | production>. The procedure is docs/PRODUCTION.md in this checkout of
jira2redmine_migration (branch <redmine70-migration | main>, commit <sha>); part A is <to do | done and checked>.
Jan is the operator and is with you.
Read docs/PRODUCTION.md completely, CLAUDE.md, and "Decided by Jan" in docs/MIGRATION-PLAN.md before you start.

Rules, without exception:
1. Jira is read-only. The credentials are in the environment, sourced from <env-file>: never print, echo, log,
   copy or write any secret, and never show one in a command (use the variables). Before your first Jira call
   in this session run the write probe of B4; continue only on HTTP 401 "scope does not match". Send nothing
   but GET requests to Jira.
2. Stay in the default permission mode.
3. Follow part A, then part B, in order, one step at a time. On the clone, check A.1 before Redmine or cron
   starts. Before a step say what it does; after it quote its summary lines and the check the document gives.
   Never call something green you did not see green.
4. Wait for Jan's explicit go at every point marked GO and at the go or rollback decision of A.7. In production
   also before every step of A.4 from step 3 on, every --confirm-push, --confirm-pull, relink --confirm-push and
   every rails runner step (manual_steps.rb workflows, members and restore; apply_workflows.rb --confirm-push),
   and run the --dry-run of every push first.
5. Production: the upgrade does not start without the snapshot of A.4 step 3, part B not without the one after
   A.5, no batch without a fresh one (B10), each confirmed by Jan. At the start of every batch check that stealth
   mode is on for the migration account and the webhooks are inactive (B5).
6. Run 09, 10, 11 and 14 in tmux. Log every step with tee into <log-dir>, outside the checkout.
7. Change no code and no config default of the migration scripts, and nothing in Redmine beyond what the steps
   of this document say. In staging, edit only the review tables Jan asks you to (B6, 04's memberships, the
   workflow review tables) and only as he states. On a failure: stop, show the log lines (and the mapping rows),
   and propose a rerun or a roll back (A.7, B10); Jan decides.
8. The repository is public: no commit, no push, no pull request from this server. Logs, verify reports and
   anything holding Jira data stay in <log-dir>.
9. Never run rake redmine:watchers:prune, never clear Redmine's tmp directory before restore, never send mail.
10. Report in Dutch, short, after part A (A.5 row by row, the duration for A.8), after every batch and at the end:
   per step the result and its duration, the counts of B7 step 10, verify, every failure with its reason, what Jan
   reviewed and decided, and everything unexpected, above all matches against objects the Redmine already had
   (B6).
```

In the rehearsal the session's findings go to Jan, who passes them on to the sessions that maintain the scripts
and the upgrade; production waits until they are fixed on `main`.
