# Production: Redmine 5.1 to 7.0, then the Jira migration

One document for the rehearsal and for production (Jan, decision 31 in docs/MIGRATION-PLAN.md). Part A
upgrades GEOxyz's Redmine 5.1 to 7.0; part B migrates Jira Cloud into that Redmine 7.0, in one run of all 14
projects (decision 39; the four batches stay as the fallback); part C is the prompt for the local Claude Code
session that runs both on the server. No hostnames, addresses or secrets in this file: the repository is public.
Placeholders are written `<like-this>`.

## Samenvatting (Nederlands)

- **Volgorde:** eerst de bestaande Redmine 5.1 naar 7.0 (deel A) en controleren, daarna Jira rechtstreeks in die
  7.0 (deel B), **in één run met alle 14 projecten** (beslissing 39): WIKI, GEOS, SIS, OAC, SURV, DOC, LABO, ICT,
  LLE, SOIL, SSPT, ROV, GSPP, FPS. TEST gaat niet mee. De vier batches blijven als terugvaloptie (B11), alleen als
  Jan dat kiest.
- **Snapshots op de drie natuurlijke pauzes** (B7, B10): na de structuur en de reviews (S1), na 09 en 10 (S2), na
  11 tot 16 (S3), plus S0 bij de start. Een fout kost zo hooguit één deel. De relink is alleen nog de controle dat
  er 0 Jira-links over zijn: in één run bestaat elk issue al voor 11 en 12.
- **Eerst een generale repetitie** op een kloon van de productie-VM: mail uit, bijlagen als steekproef, dezelfde
  run met alle projecten, de relink-controle, workflows en restore. Daarna productie met alle bijlagen.
- **Productie deel B in één onderhoudsvenster** (beslissing 36): vanaf de start is Jira alleen-lezen voor de
  gebruikers (Jan zet dat zelf) en is Redmine dicht (de webserver toont een onderhoudspagina, de migratie gebruikt
  een lokale ingang); de crontab staat uit. Na de run, de relink-controle, workflows, restore en de controles gaat
  Redmine open. Binnen het venster kost een rollback geen werk van gebruikers, daarna alleen nog vooruit fixen.
- **Nieuw risico: de bestaande Redmine.** Tot nu toe ging elke test naar een lege Redmine. De scripts zoeken eerst
  een bestaand project, gebruiker, groep, status, prioriteit, tracker of veld, en dat op naam of identifier. Een
  verkeerde match zet Jira-data in een bestaand object, een gemiste match maakt een dubbel. Jan bekijkt elke
  match voor de push (B6, met de queries).
- **Wat bestaande objecten wijzigt:** 03 voegt Jira-leden toe aan een bestaande groep met dezelfde naam, 07 en 08
  voegen trackers en projecten toe aan bestaande trackers en velden, 04 voegt rollen toe aan bestaande
  lidmaatschappen. De `workflows`-stap opent tijdelijk alle transities op alle trackers voor de rollen die Jan
  bevestigt (beslissing 33); de workflow-runner schrijft de eigen workflow per gemigreerd project en de globale
  workflow alleen van trackers die 07 nieuw aanmaakt (beslissing 34).
- **Beslist voor een bestaande Redmine (33 tot 35):** de rollen voor de tijdelijke open workflows komen uit de
  config (de repetitie maakt de inventaris, Jan bevestigt); bestaande trackers houden hun globale workflow, alleen
  de gemigreerde projecten krijgen hun eigen; Jira-prioriteiten worden gemapt op bestaande, er komen er geen bij.
  B73 (nieuwe prioriteit onderaan de rangorde) is gefixt.
- **Geen mail, geen webhooks:** elke schrijfstap van de migratie gaat via de Extended API met notify=false, sinds
  vandaag ook 13 (tags op 7.0) en de relink (Jan). Stealth mode blijft als goedkoop vangnet; webhooks uit is alleen
  nodig als de checklists-instelling "issue done ratio" aan staat (15 bewaart dan het issue via de gewone API).
  Review-aanpassingen in staging verhuizen niet mee naar productie: zet ze in de config.
- **Geen sporen van nabewerking (beslissingen 37 en 38):** 10's tweede pass, 12, 13 en de relink sturen
  `skip_journal` mee naast notify=false: geen journal, geen "Subtask added", de bijgewerkt-tijd blijft, een
  aangepast commentaar wordt niet "Edited". Daarvoor moet de Extended API versie 8e7096c of later zijn, getest is ecc7dde (B2, B5).
  Datums en % gereed van parents blijven afgeleid.
- **Duur (gemeten op de testomgeving, alle 14 projecten in één run):** 4 u 28 min, ongeveer 4 uur zonder de dubbele
  watcher-lezing die er nu uit is; productie ongeveer 6,5 tot 9 uur met de 116 GiB bijlagen, plus Jans reviews en de
  snapshots (B8). Schijf (B2): één pull van alle bijlagen, `paths.tmp`
  leeg direct na 09's push; op dat moment ongeveer 116 GiB in `paths.tmp` en 116 GiB extra in Redmine's `files/`.
  Met minder ruimte draait 09 in rondes.
- **Staging blijft bewaard** (beslissing 41): na de run wordt de staging-database versleuteld gedumpt en als controle
  teruggelezen; pas daarna, na Jans go, gaat de database weg (B9 stap 6). De dump is van Jan: hij blijft op de
  server, Jan verplaatst en beveiligt hem zelf en beslist na de overdracht wanneer hij hem wist. De dump is het
  archief van de rest van Jira's geschiedenis.
- **Jira's geschiedenis van status, toegewezene en prioriteit komt mee in de run** (beslissing 41, herzien): 11
  schrijft ze als journals met hun eigen auteur en tijd, per issue in Jira's tijdvolgorde tussen de commentaren, zodat
  "Laatst bijgewerkt door" de laatste actor uit Jira is. Een waarde die Redmine niet kent valt weg en de keten sluit
  aan; zo'n issue krijgt `jira-history.json`. Geen mail, geen webhook, de tijden van het issue blijven. Vereist de
  Extended API `b423b4c` of later (B3).
- **Auteur zonder Redmine-account** (beslissing 42, Jan): in principe heeft elke Jira-gebruiker een Redmine-account.
  Ontbreekt er nog een, dan kiest de config een gebruiker; standaard Redmine's Anonymous (het id lees je met
  `rails runner 'puts User.anonymous.id'` in `J2R_DEFAULT_AUTHOR_ID`). Dat geldt voor issues, commentaren,
  geschiedenis, `jira-history.json` en bijlagen. Zonder die waarde stoppen de scripts in plaats van als het
  migratie-account te schrijven.
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
  logs every step and reports in Dutch after each part of the run.
- **Branch (decision 32):** the rehearsal runs from `redmine70-migration`; production runs from `main`, once
  the branches are on main and checked identical to what the rehearsal ran.

The detailed background of every step (why it is there, what was measured) is in docs/RUNBOOK.md and in
docs/MIGRATION-PLAN.md (Jan's decisions, findings B1 to B72). This document is the procedure.

<!-- Part A is written by the coordinator session and included here as written. -->

## Part A: Upgrade Redmine 5.1-stable-GEOxyz to 7.0-stable-GEOxyz

Written by the coordinator from the upgrade rehearsal of 2026-10-14/15 and the "After the upgrade
(production)" section of each plugin's `docs/REDMINE7-MIGRATION.md` (branch `redmine70-migration`). Jan's
order (2026-10-15): upgrade first, check, then migrate Jira into 7.0 (Part B).

### A.0 What was proven, and what this run proves for the first time

Proven in the cloud test environment: a 5.1-stable-GEOxyz database with the 44 production plugins and
seeded data (not production data), upgraded in place to 7.0-stable-GEOxyz `71b9479` with the 44 plugins on
`redmine70-migration`. Since 2026-10-19 the tested core is `7310d11` (the merge of upstream 7.0-stable `37012d5`: no
conflicts, no migrations, no gem changes; core suite 6,270 runs, 0 failures). The 44 plugins together on `7310d11`
(PostgreSQL 16, Ruby 3.3.6, Rails 8.1.4, each on its `redmine70-migration` head, more_previews with its sandbox
required): 8,289 minitest runs and 7,244 rspec examples, 0 failures (the issue_templates drag-and-drop timing spec
passed on one retry). On the way redmine_view_issue_description `ad36481` fixed a per-request memo that broke 4 of
redmine_agile's query-count tests. `db:migrate` and `redmine:plugins:migrate` exit 0 with no manual workaround; the schema
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
- **Tools on the server**: `pandoc` (core Office preview, more_previews "mark"); ImageMagick and Ghostscript only
  if more_previews "maggie" stays on (it is off after the hand-over task in A.5). Chromium for reporter_dashboards
  PDF. For more_previews "libre" (redmine_more_previews `c2b8c27`, Jan 2026-10-19: Libre runs only inside a
  bubblewrap sandbox, without a fallback; without a working sandbox Libre refuses and stays off):
  - LibreOffice from the distribution, exposing `/usr/bin/soffice`
    (`apt install libreoffice-writer libreoffice-calc libreoffice-impress` plus the fonts in use); a TDF .deb under
    `/opt` reached through PATH is not supported by the sandbox.
  - `apt install bubblewrap`. On Ubuntu 23.10 and later (24.04 included) `kernel.apparmor_restrict_unprivileged_userns=1`
    blocks bwrap for non-root users. The narrowest fix: if `/etc/apparmor.d/bwrap-userns-restrict` exists, make sure
    it is loaded (`aa-status | grep bwrap`); otherwise create `/etc/apparmor.d/bwrap` with

    ```text
    abi <abi/4.0>,
    include <tunables/global>
    profile bwrap /usr/bin/bwrap flags=(unconfined) {
      userns,
      include if exists <local/bwrap>
    }
    ```

    and load it with `apparmor_parser -r /etc/apparmor.d/bwrap`. Do not switch the sysctl off system-wide.
    `user.max_user_namespaces` must be above 0.
- **Web server**: https; WebSocket upgrade on `/rup_cable` (redmine_zenedit's editing indicator, redmine_agile
  live boards), e.g. nginx `proxy_http_version 1.1; proxy_set_header Upgrade $http_upgrade; proxy_set_header
  Connection $connection_upgrade; proxy_set_header X-Forwarded-Proto https;`. With a Content-Security-Policy:
  `script-src` must allow the mermaid host (default `cdn.jsdelivr.net`). Large attachments: the Apache appendix
  of the runbook.
- **`config/database.yml` without ERB**: the 5.1 GEOxyz change that allowed ERB there during `bundle install`
  was not carried to 7.0 (Jan deferred it). If production's file uses `<%= %>`, write the values in plainly
  for the upgrade.

### A.3 What to deploy

Core: `jcatrysse/redmine`, branch `7.0-stable-GEOxyz`, tested at `7310d11` (rehearsal: the commit named in the
report; production: the same commit). Plugins: one directory per row; **the directory name matters** (it is the plugin id stored in
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
    `RAILS_ENV=production bundle exec rake redmine_more_previews:cache:clean DAYS=0 CONFIRM_PURGE_ALL=1` (the cache
    identity changed; upstream's opt-in purge of every preview cache, skipping previews in use; the daily `DAYS=30`
    cron of A.6 stays), `rm -rf public/plugin_assets/calendar_events_daily`.
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
| 5 | Libre sandbox (more_previews round 6) | as the Redmine user: `sudo -u <redmine-user> /usr/bin/env -i /usr/bin/bwrap --die-with-parent --new-session --unshare-user --unshare-pid --unshare-net --unshare-ipc --unshare-uts --ro-bind /usr /usr --symlink usr/bin /bin --symlink usr/lib /lib --symlink usr/lib64 /lib64 -- /usr/bin/true && echo sandbox ok`; then Administration > Information shows "LibreOffice available in its sandbox (bubblewrap)" ticked; preview a .docx and an .odt as PDF and as HTML | `sandbox ok`, the box ticked, the four previews show. If the sandbox does not work: Libre previews stay off (Jan, 2026-10-19), the rest of the upgrade goes on |
| 6 | computed custom fields | `bundle exec rake redmine:computed_custom_field:check_formulas` | exit 0, no `FAIL` (fix a FAIL in Administration > Custom fields) |
| 7 | custom workflow scripts | `bundle exec rake redmine:custom_workflows:audit` | no script reported |
| 8 | view_customize | `bundle exec rails runner plugins/view_customize/docs/audit_snippets.rb`; scripts that read the API key in an unusual way get "use API key" ticked by hand | snippets fixed only where broken (Jan) |
| 9 | stored SQL fields | run every statement of step 2 once on 7.0; `%<name>d` or `%s` placeholders become `%{name}` | each statement runs; the new issue form opens |
| 10 | digest rules | `bundle exec rails runner 'IssueDigestRule.find_each { \|r\| puts [r.id, r.valid?].inspect }'`; rules with timezone `Etc/GMT+12` corrected in the form | every rule `true` |
| 11 | tags | `bundle exec rails runner 'p Issue.where.not(cached_tag_names: [nil, ""]).count'` | the number of tagged issues; tag filters and the issue report open |
| 12 | checklists | Administration > Roles: `done_checklists` for the roles that tick items | as before |
| 13 | issue view columns | `bundle exec rails runner 'p Setting.related_issues_default_columns'`; Settings > Issue tracking > "Show table headers" on if the captions are wanted | the plugin's global columns |
| 14 | small clean-ups | `DELETE FROM wiki_extensions_menus WHERE menu_no NOT BETWEEN 1 AND 5;` and, optional, `UPDATE agile_data SET agile_sprint_id = NULL WHERE agile_sprint_id = 0;` | |
| 15 | settings that changed meaning | Plugins > Redmine Edit Issue Author: "Possible authors" (new default: project members who may add issues); Roles: HR roles "Users visibility" = all active users (redmine_people); helpdesk "Add contact notes" off per confidential project (helpdesk document, q7); mail_digest "Allow email-address recipient lookup" if rules use addresses | as decided |
| 16 | Project > Settings, every tab | as admin and as a project manager; `grep -rnE "^\s*alias_method.*project_settings_tabs" plugins/` prints nothing | no error page |
| 17 | REST API and text formatting | Administration > Settings > Integrations (REST on); General > Text formatting = CommonMark | on, CommonMark |
| 18 | LDAP sync (real AD) | `DRY_RUN=1 bundle exec rake redmine:plugins:ldap_sync:sync_all`, read it (the first run creates groups and memberships that 5.1 never synced), then the real run; log in as one account in the required group, one outside, one disabled in AD | as the AD says |
| 19 | SSO | `bundle exec rake redmine:bless_this_sso:status`; the entry point is now `/oauth/sso/authorize` (callback unchanged) | production: login, SSO-only, sign out |
| 20 | the whole site | open the issue list, an issue, the agile board, the calendar, the gantt, My page, a wiki page, a helpdesk ticket, a contact, the people page, as admin and as a normal user; on the clone Claude can crawl every menu link and report any 500 | no error page |

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
| outgoing mail, webhooks, cron | off on the clone (A.1: crontab off, mail to files, webhooks inactive); that covers part B too | on for the users; every issue write of the migration goes through the Extended API with notify=false, stealth mode as a safety net (B5) |
| attachments | `attachments.test_sample = true`: every file up to 512 KB plus the largest per extension up to 20 MB; the rest is noted "skipped by sample" | `test_sample = false`: every file |
| snapshot | A.4 step 3 is the upgrade's rollback point; part B's S0 is the snapshot after A.5; S1 to S3 inside the run (B7), each timed | A.4 step 3; S0 at the start of the window (B5), then **S1 to S3** at the run's pauses (B7, B10); no part of the run starts without the snapshot before it |
| GO | at the review points (B6, B7 steps 1, 3 and 14, B9) | at the review points and before every write |
| Jira | the live Jira, read-only for the token (B4); the data is what Jira holds that day | read-only for the token, and for the users from the start of the window (decision 36) |
| users | none on the clone | **one maintenance window** for B5 to B9 (decision 36): Redmine closed to users, the crontab off (B5) |
| disk for attachments | the sample: a few GB | about 116 GiB in `paths.tmp` and 116 GiB more in `files/` at the end of 09's push (B2) |
| afterwards | the clone is thrown away; findings go to Jan; B9 step 6 is practised on the clone's staging and that dump deleted | Redmine stays; staging is dumped and encrypted (Jan's dump, on the server for him to move), then the database is removed (B9 step 6, decision 41) |

Everything else is the same: the run, the commands, the checks. The rehearsal is there to find what an
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
  (`rake tmp:clear`) between the `workflows` step and `restore`.
- **A temporary MariaDB or MySQL 8** for the staging database, on the same VM so that one VM snapshot holds
  Redmine and staging together (B10). Install and create it as in docs/RUNBOOK.md, Prerequisites 2:

  ```bash
  apt install mariadb-server
  mysql -e "CREATE DATABASE jira2redmine CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER 'jira2redmine'@'localhost' IDENTIFIED BY '<staging-password>';
    GRANT ALL ON jira2redmine.* TO 'jira2redmine'@'localhost';"
  mysql jira2redmine < database/schema.sql
  ```
- **Disk (B2's choice, decision 39): one pull of every attachment, `paths.tmp` emptied right after 09's push.**
  `paths.tmp` holds the downloaded attachments (in `attachments/`); 09 does not delete them after the upload, and
  Redmine's `files/` grows by the same amount. The 14 projects hold about 116 GiB (B8), so at the end of 09's push
  production needs **about 116 GiB in `paths.tmp` and 116 GiB more in Redmine's `files/`**, plus a margin (Jira keeps
  growing; the rehearsal's counts say how much). Right after the push `paths.tmp` is emptied (B7 step 7): nothing
  later reads the local copies, and S2 then carries only `files/`. Why one pull: it is the simplest (one pull, one
  push, one check) and the same as in every test run. **With less room**, 09 runs in rounds (`--download-limit`), each
  round pulled, pushed and emptied before the next (B7 step 7): the peak in `paths.tmp` is then one round, at the cost
  of a GO per round. With the sample (rehearsal) the whole run pulls a few GB (SURV alone 0.98 GB). The staging
  database stays small next to that (a few GB: the Jira payloads of every issue and comment).
- **tmux** for the long steps (B8), so a dropped SSH session does not stop a step.
- **Redmine 7.0** after part A, with the plugins the migration uses: `redmine_extended_api` (issues, journals,
  relations without mail and with Jira's authors and dates; **8e7096c or later**, for `skip_journal`, decision 37; tested: `ecc7dde`),
  `redmine_project_workflows` (each project its own
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
            // content of Jira app accounts and of people without a visible e-mail (B6): Redmine's Anonymous user
            // unless Jan picks another (decision 42); the same for journals, history and attachments
            'default_redmine_author_id' => (int)getenv('J2R_DEFAULT_AUTHOR_ID'),
        ],
        'journals' => ['default_redmine_author_id' => (int)getenv('J2R_DEFAULT_AUTHOR_ID')],
        // decision 35: Jira priority => existing Redmine priority, for every Jira priority Redmine has under
        // another name (06 lists them as MANUAL; the rehearsal settles the list)
        'priorities' => ['mappings' => []],
        // decision 33: the roles the workflows step opens, as Jan confirmed after the inventory of B5
        'workflows' => ['open_workflow_roles' => ['Manager', 'Developer']],
    ],
    'attachments' => [
        'download_concurrency' => 6,
        'test_sample' => getenv('J2R_TEST_SAMPLE') === '1',
        // decision 42: the uploader of a Jira file whose author has no account
        'default_redmine_author_id' => (int)getenv('J2R_DEFAULT_AUTHOR_ID'),
    ],
];
```

The environment, in a file only the operator can read (`chmod 600`), outside the checkout, sourced in every shell
and tmux window: `JIRA_SITE` (`<site>.atlassian.net`), `JIRA_EMAIL`, `JIRA_API_TOKEN`, `JIRA_CLOUD_ID` (from
`https://<site>.atlassian.net/_edge/tenant_info`), `REDMINE_URL` (production: the local-only entry of the
maintenance window, `http://127.0.0.1:8080`, B5), `REDMINE_API_KEY` (an admin's API key: the migration account), `J2R_STAGING_PASSWORD`, `J2R_TMP` (the attachment directory), `J2R_DEFAULT_AUTHOR_ID` (decision 42: the Redmine user
of Jira content whose author has no account, Redmine's Anonymous user unless Jan picks another; read it in the Redmine
directory with `RAILS_ENV=production bundle exec rails runner 'puts User.anonymous.id'`; 09, 10 and 11 stop without it),
`J2R_PROJECTS` (the 14 projects, lowercase, B8), `J2R_TEST_SAMPLE` (`1` in the rehearsal only). Never print them, never paste
them in a chat, never commit them.

Everything else keeps the defaults of `config/config.default.php`, which hold Jan's decisions: TEST not
migrated (22), the service desk fields and Jira's "Agent Sessions" not migrated (20, 40), the role mappings and B31 proposals (10), the
workflow mode `project` and the group settings (13, 14, 19), the comment attachment window 0 (27), the
GEOxyz relation types. Check that the local file loads, without printing a secret:
`php -r '$c = require "bootstrap.php"; echo implode(",", $c["migration"]["issues"]["projects"]), " | ", implode(",", $c["migration"]["projects"]["not_migrated"]), PHP_EOL;'`
prints the run's projects and `TEST`.

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

### B5. Before the run

**Production: one maintenance window (Jan, decision 36).** Part B, from this section to the end of B9, runs in one
window; part A is its own window before it (decision 29). Why: the scripts skip an issue that is already migrated, so
a change made in Jira after the extraction would be lost, and a rollback to a snapshot would erase what users did
in Redmine meanwhile. The rehearsal measures the window's length (B8 estimates 7 to 12 hours of run time in
production, plus Jan's reviews and the snapshots); its report gives the real figure, which sets the window.

1. **Announce** the window (Jan): from its start Jira is read-only and Redmine closed; afterwards everyone works in
   Redmine.
2. **Jira read-only for the users** (Jan, by hand, as a Jira admin; the scripts and the session never write to Jira,
   the token cannot): for example a permission scheme with Browse projects only on the 14 migrated projects, or the
   create, edit, transition and comment permissions taken out of the schemes in use. Jan confirms it before the run.
3. **Redmine closed to the users.** Redmine has no maintenance mode of its own; close it at the web server and give
   the migration a local-only entry. With Apache (the appendix of docs/RUNBOOK.md; the same idea with nginx):

   ```apache
   # in the public virtual host, for the window: every request gets the maintenance page
   RewriteEngine On
   RewriteCond %{REQUEST_URI} !=/maintenance.html
   RewriteRule ^ - [R=503,L]
   ErrorDocument 503 /maintenance.html

   # the migration's own entry, reachable from the server only
   Listen 127.0.0.1:8080
   <VirtualHost 127.0.0.1:8080>
       DocumentRoot /srv/redmine/public
       PassengerAppRoot /srv/redmine
       PassengerAppEnv production
       LimitRequestBody 4294967296
       TimeOut 600
       XSendFile On
       XSendFilePath /srv/redmine/files
       SetEnv REDMINE_X_SENDFILE_HEADER X-Sendfile
   </VirtualHost>
   ```

   `public/maintenance.html` is a plain page saying when Redmine opens. For the window `REDMINE_URL` is
   `http://127.0.0.1:8080` (B3); the `rails runner` steps do not use HTTP. Check: from another machine every page,
   the login and the REST API answer 503; on the server `curl -s -o /dev/null -w '%{http_code}' "$REDMINE_URL/login"`
   gives 200. While Redmine is closed, the accounts the `members` step activates and the workflows the `workflows`
   step opens reach nobody: an activated former employee cannot log in or use an API key, the web server answers 503.
4. **The crontab of the Redmine user off** for the window (`crontab -l` saved, then `crontab -r`): mail fetching,
   digests, recurring issues, scheduled reports, AI triage, LDAP sync and attachment prune all stop; nothing changes
   Redmine or mails from it, and a rollback to a snapshot loses no fetched mail (it waits in the mailbox). B9
   puts it back.

Then, once, on the upgraded Redmine 7.0. Each line is a check the local session reports.

| what | how | check |
|---|---|---|
| snapshot S0 | part B's rollback point (A.4 step 3 is the upgrade's). Rehearsal: the snapshot after A.5. Production: taken now, at the start of the window, after steps 1 to 4 above (users worked in Redmine since part A). B7 takes S1 to S3 (B10) | the snapshot exists and its time is noted |
| REST API | Administration > Settings > Integrations: REST web service on (A.5 row 17; "API" on 5.1) | `curl -s -H "X-Redmine-API-Key: $REDMINE_API_KEY" "$REDMINE_URL/users/current.json"` gives the migration account, `admin: true` |
| Extended API | the plugin is installed, version `b423b4c` or later (tested: `b423b4c`): the scripts send `skip_journal` (decision 37, 8e7096c or later; an older version ignores it, so its journals would come back) and 11 writes Jira's history through its history endpoint (decision 41; without it 11 stops the history with one `[error]` and pushes the comments) | `curl -sI -H "X-Redmine-API-Key: $REDMINE_API_KEY" "$REDMINE_URL/extended_api/issues.json"` has the header `X-Redmine-Extended-API`; `grep -c skip_journal <redmine-root>/plugins/redmine_extended_api/lib/redmine_extended_api/patches/api_helpers.rb` is above 0; `ls <redmine-root>/plugins/redmine_extended_api/app/controllers/extended_api_issue_histories_controller.rb` exists |
| default author (decision 42) | Jira content whose author has no Redmine account (an app, a person 02 could not match) goes to Redmine's Anonymous user, unless Jan picks another account: in the environment file `export J2R_DEFAULT_AUTHOR_ID=<id>` with the id from `cd <redmine-root> && RAILS_ENV=production bundle exec rails runner 'puts User.anonymous.id'` (it differs per Redmine). It covers the issue author (10), comments and changelog entries (11), history journals and `jira-history.json` (11) and the attachment uploader (09); without it those scripts stop instead of writing as the migration account | `php tools/config_value.php migration.issues.default_redmine_author_id` and `... migration.journals.default_redmine_author_id` print that id |
| text formatting | Administration, Settings, General: CommonMark Markdown (A.5 row 17) | `Setting.text_formatting` is `common_mark` |
| no mail, no webhook from the migration | `redmine.extended_api.enabled` true (B3): 10, 11, 12, 13 (tags on 7.0) and the relink write issues and journals through it with notify=false, which silences the mails and Redmine 7's webhooks of the request (Jan, 2026-10-15), and 10's second pass, 12, 13 and the relink also with `skip_journal` (no journal, decision 37); 11's history (decision 41) goes through the history endpoint, a raw insert that runs no callback at all (measured: 0 mails, 0 webhook calls on 5.1 and 7.0); 09's uploads and 14's watchers send none. The one exception: 15 saves the issue's done ratio through the plain API when redmine_checklists' setting "issue done ratio" is on (Administration, Plugins, Checklists; off by default): switch it off for the run. Safety net (production, cheap, B2's advice): stealth mode on for the migration account, `curl -s -X POST -H "X-Redmine-API-Key: $REDMINE_API_KEY" -d toggle=true "$REDMINE_URL/stealth/toggle.json"`; it silences the mails of anything the migration account writes outside the Extended API. Rehearsal: outgoing mail is off on the clone (A.1) | the checklists setting is off; production: the answer is `{"is_cloaked":true}` (signing in to the web interface as the migration account switches stealth off again: do not, or toggle it on after) |
| relation types | the GEOxyz relation types of `migration.relations.custom_mappings` in `redmine_issue_view_columns` (`tools/testenv/redmine_issue_view_columns.local.rb` is generated from them) | after part A they exist in 7.0 (12 refuses a relation type Redmine does not know) |
| attachment prune and LDAP sync off | covered by the crontab being off (step 4 above; on the clone A.1): prune would delete uploads still waiting for their issue after 24 h, LDAP sync would lock or change accounts the migration activates | `crontab -l` for the Redmine user is empty |
| webhooks | no change needed: every issue write goes through the Extended API with notify=false, and Redmine 7 fires no webhook for it. Only if the checklists setting of the row above must stay on: `UPDATE webhooks SET active = false;` for the duration of part B, after noting which were active (`SELECT id, url, active FROM webhooks;`); stealth mode does not silence webhooks. Rehearsal: inactive anyway (A.1, A.4 step 11) | |
| automation rules | Jan exports them once as JSON (docs/RUNBOOK.md, section 0); kept outside the repository | the file opens as JSON |
| roles for the open workflows (decision 33) | the inventory of the roles that take part in workflows (Add or Edit issues) and how many memberships hold them: `RAILS_ENV=production bundle exec rails runner 'Role.givable.sorted.select(&:consider_workflow?).each { \|r\| puts [r.id, r.name, r.has_permission?(:add_issues) ? "add_issues" : "-", r.has_permission?(:edit_issues) ? "edit_issues" : "-", MemberRole.where(role_id: r.id).count].join(" \| ") }'`. **GO:** Jan confirms the list in `migration.workflows.open_workflow_roles` (B3); one usable role is enough, `["Migration (temporary)"]` keeps the open workflow away from the existing projects (B6) | `php tools/config_value.php migration.workflows.open_workflow_roles` prints Jan's list |
| staging | the empty staging database of B2 | `SHOW TABLES` lists the tables of `database/schema.sql` (81 today) |

The `workflows` step of `manual_steps.rb` (B7 step 2) also sets three global settings Jan decided, which stay after
the migration and apply to every project: parent priority independent (B13), cross-project subtasks "With all
projects" (B38), cross-project issue relations allowed (B39).

### B6. Matching against an existing Redmine (01 to 08): what Jan reviews

Every test run so far went into an empty Redmine. GEOxyz's Redmine has its own projects, users, groups, roles,
statuses, priorities, trackers and custom fields, and each of 01 to 08 first looks for an existing one and uses it
(`MATCH_FOUND`) instead of creating one. The match is on the identifier, the login or e-mail, or the **name only**,
without case, and without any check that the existing object means the same thing. A wrong match puts Jira data
into an existing object; a missed match creates a second one. This is the new risk of the rehearsal; Jan reviews every match
before its push (**GO**).

| script | matched on | what the push changes on an existing object | Jan reviews |
|---|---|---|---|
| 01 projects | the Jira key, lowercased, against the identifier of the **active** projects (closed and archived projects are not in Redmine's list) | nothing on the project itself; but the run then puts the Jira issues into it, 07 adds trackers, 08 enables fields, 04 adds memberships | every `MATCH_FOUND`: is this the project the Jira issues belong in? A key that is the identifier of a closed or archived project fails with 422 "Identifier has already been taken" (`CREATION_FAILED`) |
| 02 users | the Jira e-mail against the Redmine login, then the primary e-mail; locked and registered accounts included | nothing; the `members` step activates a matched locked account for the run and `restore` locks it again | new accounts for a person who already has one under another e-mail (a second account), an e-mail that is only an additional address of an account (create fails "Email has already been taken"), logins Redmine refuses (a `+`, more than 60 characters) |
| 03 groups | the group name | **the Jira group's members are added to the existing group** (none removed): they get every role that group has in every project | every matched group; one that only shares a name: set its row `IGNORED` before the push |
| 04 roles and memberships | `migration.roles.mappings`, else the Jira role's own name | no role definition changes; a membership that exists gets the proposed role added (none removed); the `<group> (workflow)` roles of decision 14 are created, or reused when one has that name | the membership proposal (B7 step 3); on a project that existed before, a Non member row set READY opens it to every logged-in user |
| 05 statuses | the name | nothing; open or closed is **taken from Redmine**, not from Jira's category | matched statuses whose Jira category (`done`) and Redmine's closed flag disagree (listed first by the query) |
| 06 priorities | `migration.priorities.mappings` (Jira name => Redmine name), else the name (decision 35) | nothing: one priority scheme for every project; a Jira priority without a match is a review row (MANUAL) and 06 creates none (only with `migration.priorities.create_missing`; then after the existing ones, B73) | every MANUAL row: put the mapping in `migration.priorities.mappings` (it carries over to production) and run 06 again; 10 holds back the issues of an unmapped priority |
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

- `manual_steps.rb workflows` (B7 step 2) opens every transition on **every tracker** of the Redmine, as global
  rules, for the roles in `migration.workflows.open_workflow_roles` (decision 33; default Manager and Developer):
  until `restore`, those roles can make any status change in every project that uses the global workflow, the
  existing projects included. `restore` removes exactly what it added. It also gives those roles
  `view_watched_issues` and `view_issue_description` (they keep them) and sets the three settings of B5. A role the
  Redmine lacks, or one without Add or Edit issues, stops the step (before, a missing name opened nothing and every
  issue landed in its tracker's default status, B12). The import runs as an admin, who gets every role's
  transitions, so one usable role is enough: with `["Migration (temporary)"]` the step creates that role, and the
  open workflow reaches only the migrated users in the migrated projects (the `members` step gives it to them), not
  the existing projects; `restore` deletes the role with its transitions. Jan confirms the list after the inventory
  of B5.
- `manual_steps.rb members` (B7 step 4) activates locked users and gives the temporary role: on an existing Redmine
  only with `J2R_USER_IDS_FILE`.
- `apply_workflows.rb` (B9 step 2) gives each migrated project its own workflow (mode `project`, through
  redmine_project_workflows). Decision 34: the cleaned union becomes the **global** workflow only of the trackers 07
  created (decision 18 for those); a tracker matched to one Redmine already had keeps its global workflow (no union
  rows, no global transitions removed, no field permissions), so existing projects without a workflow of their own
  see no change. `tools/workflow_plan.php` prints `Global workflow left as it is (decision 34) for N tracker(s)
  Redmine already had: ...`; `restore` then takes the temporary open transitions of those trackers away again.

**B73 (fixed):** Redmine's priority list in the REST API has no position; 06 proposed 1, 2, 3 for new priorities and
Redmine inserted them below every existing one (the new "Blocker" became the lowest on the 7.0 test instance). A
created priority now goes after the existing ones; since decision 35 06 creates none by default.

**Review edits do not travel.** Staging is new in production, so an edit made in the rehearsal's staging tables
is gone there. Put every choice that has a configuration key in `config/config.local.php` (priorities: decision 35;
roles: `migration.roles.mappings`; the workflow roles: decision 33; groups in workflows: decision 14), and write the
other edits down as SQL in the rehearsal report, to replay in production at the same review point.

### B7. The run, step by step

All 14 projects in one run (Jan, decision 39): `J2R_PROJECTS` lists them all from the first step (B8). Three VM
snapshots split the run at its natural pauses, so a failure costs at most one part of it (B10): **S1** after the
structure and its reviews (step 6), **S2** after 09 and 10 (step 9), **S3** after 11 to 16 (step 11). S0 is part B's
starting point (B5). The four batches of decision 22 stay as the fallback (B11), only when Jan chooses it.

Each script: first the analysis (`php NN_x.php`: jira, redmine and transform phases), read its summary
(`Matched / Ready / Manual`), then **`--phases=push --dry-run`** (what the push would send, without writing),
then **`--phases=push --confirm-push`**. In the commands below the dry-run is left out for brevity; in production
it comes before every push. Every step's output goes to a log, for example
`php 01_migrate_projects.php 2>&1 | tee "$LOG/01.log"` with `LOG=~/j2r-runs/<rehearsal|production>` outside the
checkout (the logs hold Jira data: never in the repository).

1. **Structure: projects, users, groups, statuses, priorities, trackers.** Review the analysis before each push
   (B6). **GO:** Jan reads B6's review list, every match of the 14 projects.

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
2. **Open the workflows** (on the Redmine server, in the Redmine root; B6 says what this touches):

   ```bash
   J2R_WORKFLOW_ROLES="$(php <checkout>/tools/config_value.php migration.workflows.open_workflow_roles)" \
     J2R_STEP=workflows RAILS_ENV=production bundle exec rails runner <checkout>/tools/testenv/manual_steps.rb
   ```

   Output: `workflow transitions created=N roles=[...] ...`; `roles` is Jan's confirmed list (decision 33, B5). A
   role the Redmine lacks or one without Add or Edit issues stops the step with its name. In production Redmine is
   closed to users meanwhile (B5): the open workflows reach nobody.
3. **Extract issues, attachments, watchers; propose the memberships.**

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
4. **Activate locked users and give the temporary migration role** (decision 12; Redmine server, Redmine root).
   `J2R_USER_IDS_FILE` is **required** on an existing Redmine: without it the step activates every user of the
   Redmine. The step finds the projects by identifier: the lowercase `J2R_PROJECTS` (the Jira key, lowercased, is
   the Redmine identifier, B6).

   ```bash
   mysql -N jira2redmine -e "SELECT redmine_user_id FROM migration_mapping_users WHERE redmine_user_id IS NOT NULL" > "$J2R_TMP/user_ids.txt"
   J2R_STEP=members J2R_USER_IDS_FILE="$J2R_TMP/user_ids.txt" \
     RAILS_ENV=production bundle exec rails runner <checkout>/tools/testenv/manual_steps.rb
   ```

   Output: `activated=N temporary memberships=N projects=N members=N`; `projects` must be 14 (the test run:
   `projects=14`, LLE and FPS included, which have no issues). The activated accounts (former employees among them) stay active until
   `restore` (B9), which comes before Redmine opens; in production nobody can log in with them meanwhile, the web
   server answers 503 (B5).
5. **Custom fields.** Review the analysis (B6: fields matched to existing Redmine fields), then push.

   ```bash
   php 08_migrate_custom_fields.php --phases=usage,redmine,transform
   php 08_migrate_custom_fields.php --phases=push --confirm-push
   ```

   The transform lists `[unoffered]` values (B37) to retire after the run (docs/RUNBOOK.md, section 2).
6. **Snapshot S1** (production; the rehearsal takes it too and notes how long it took). Everything Jan reviewed is
   pushed; 09 to 16 only add issue data.
7. **Attachments** (long; tmux). One pull of every attachment, then the upload, then `paths.tmp` emptied (B2):

   ```bash
   php 09_migrate_attachments.php --phases=pull --confirm-pull
   php 09_migrate_attachments.php --phases=transform,push --confirm-push
   ```

   Check: `Jira attachment download summary — queued: N, downloaded: N, failed: 0` and `Attachment upload summary —
   ... failed: 0`. Then empty the attachment directory (09 makes it again when needed):
   `rm -rf "$J2R_TMP/attachments"`. Nothing later reads the local copies; a failed upload
   is requeued by 09's transform and downloaded again, so emptying is safe. Not enough disk for one pull (B2): rounds,
   each pulled, pushed and emptied before the next, until the pull's queue is 0:

   ```bash
   php 09_migrate_attachments.php --phases=pull --confirm-pull --download-limit=1500
   php 09_migrate_attachments.php --phases=transform,push --confirm-push
   rm -rf "$J2R_TMP/attachments"
   ```
8. **Issues** (tmux). Twice: the second run writes the descriptions that link issues created later in the run and
   the parents created after their child.

   ```bash
   php 10_migrate_issues.php --phases=transform,push --confirm-push
   php 10_migrate_issues.php --phases=transform,push --confirm-push
   ```

   Check: `[open-parent]` lines (a closed parent with an open subtask gets "Resolved", Jan's rule) and
   `Issues waiting for a parent outside the run (B38)`: a `[waiting]` line may only name a project that is not
   migrated (TEST). 10 sends each issue's Jira labels as tags with the create (no journal).
9. **Snapshot S2** (production; rehearsal as at S1). The issues and their attachments are in.
10. **History and links** (11 and 14 long; tmux):

    ```bash
    php 11_migrate_journals.php --confirm-push --use-extended-api
    php 12_migrate_issue_relations.php --confirm-push
    php 13_migrate_tags.php --confirm-push
    php 14_migrate_watchers.php --phases=transform,push --confirm-push
    php 15_migrate_checklists.php --confirm-push
    php 16_export_workflows.php
    ```

    In one run every issue exists before 11 and 12: the comments get their `#id` links directly, and 12 leaves a
    relation MANUAL only when its other side is not migrated. 13 sends the tags of every tagged issue again
    (1,601 issues, 4 minutes in the test run of the 14 projects); for an issue 10 created with its tags that changes
    nothing, and it goes through the Extended API with notify=false and `skip_journal`: no journal, the updated time
    kept. 14 reads nothing from Jira here: its jira phase ran in step 3. 10's second pass and 12 send `skip_journal` too (decision 37): the parents get no
    "Subtask added", the relations no journal on either issue.

    11 writes Jira's history of status, assignee and priority too (decision 41): per issue in Jira's time order,
    between the comments (Redmine's "Last updated by" is the journal with the highest id, so the last person who
    acted in Jira stays last), with Jira's updated time of the issue kept. Check in its log:
    `History: <n> issue(s), <n> journal(s), ...; status mismatches: 0 (open parents: <n>)` (a `[note]` line per open
    parent: its history ends in Jira's status, 10 gave it "Resolved"; a `[warn]` line is a history that ends
    elsewhere: report it) and at the end `History pushed: <n> journal(s), <n> file(s), 0 failed request(s)`. A value
    Redmine does not have (LABO's 7 statuses no project uses, an assignee without an account) is left out and the
    chain stays connected; such an issue gets `jira-history.json`. Expected from the test runs: about 4,400 journals
    for GEOS, SIS, OAC and LABO, 185 files in LABO, a few elsewhere (B8). A rerun sends only what is missing.
11. **Snapshot S3** (production; rehearsal as at S1). The migration itself is complete; B9 follows.
12. **Counts** (staging):

    ```bash
    mysql jira2redmine -e "SELECT 'issues', migration_status, COUNT(*) FROM migration_mapping_issues GROUP BY 2
      UNION ALL SELECT 'journals', migration_status, COUNT(*) FROM migration_mapping_journals GROUP BY 2
      UNION ALL SELECT 'attachments', migration_status, COUNT(*) FROM migration_mapping_attachments GROUP BY 2
      UNION ALL SELECT 'relations', migration_status, COUNT(*) FROM migration_mapping_issue_relations GROUP BY 2
      UNION ALL SELECT 'watchers', migration_status, COUNT(*) FROM migration_mapping_watchers GROUP BY 2
      UNION ALL SELECT 'comment reads', comments_migration_status, COUNT(*) FROM staging_jira_journal_extract_state GROUP BY 2
      UNION ALL SELECT 'history reads', changelog_migration_status, COUNT(*) FROM staging_jira_journal_extract_state GROUP BY 2
      UNION ALL SELECT 'history journals', 'pushed', COUNT(history_journal_id) FROM migration_mapping_journals
      UNION ALL SELECT 'history files', 'pushed', COUNT(history_attachment_id) FROM migration_mapping_journals"
    ```

    Expected: comment and history reads SUCCESS (WARNING only for an issue Jira refuses, 401, 403 or 404); a FAILED
    read left that issue without its comments or history (B81: a full disk, "The table ... is full"): fix the cause,
    run 11 again, it reads only those issues. Issues CREATION_SUCCESS, plus READY_FOR_REWRITE only for a parent that is not migrated; relations
    CREATION_FAILED only with Redmine's reason in `notes` (the test run had one: Jira links the same two issues twice,
    "blocks" and "relates", and Redmine refuses the second as a circular dependency); relations
    MANUAL only for an other side that is not migrated ("Missing Redmine issue mapping"); no `FAILED` anywhere
    without a reason in `notes`; rehearsal: attachments PENDING_DOWNLOAD = the files skipped by the sample.
13. **Verify:**

    ```bash
    php tools/verify.php --projects="${J2R_PROJECTS^^}" --sample=25 --report="$LOG/verify.md"
    ```

    The last line must be **PASS**. The report holds issue keys and short Jira texts: it stays in `$LOG`. Decision
    41 adds two checks per sampled issue: "journals: last updated by the latest" and "history: last status as in
    Jira"; `jira-history.json` is not counted as a Jira attachment.
14. **GO: Jan looks** at a few migrated issues of every project in Redmine (description, comments, attachments,
    watchers, status) next to Jira, and gives the go for B9.
15. **Errors in the logs:** `grep -ciE '\[error\]|fatal|exception' "$LOG"/*.log` is 0 for every log, or each hit is
    explained in the report.
16. **Report** (Dutch, short): counts, durations per step and per snapshot, failures and their reason, the review
    points and Jan's answers.

### B8. The run: projects, sizes and durations

`J2R_PROJECTS` for the run (decision 39), lowercase because `manual_steps.rb` finds the projects by their Redmine
identifier (the configuration uppercases the list for the scripts, B3):

```text
wiki,geos,sis,oac,surv,doc,labo,ict,lle,soil,sspt,rov,gspp,fps
```

TEST is not migrated (decision 22). The order inside the run does not matter: 10 creates every issue before its
second pass, 11 and 12 run when every issue exists.

**Sizes** (the coordinator's count through the gateway on 2026-10-13, GET only; attachments in GiB, 1 GiB = 1,024
MiB; LLE, FPS and TEST had no issues that day; "batch" is the fallback's, B11):

| batch | project | issues | comments | attachments | GiB | largest file MiB | links | subtasks |
|---|---|---|---|---|---|---|---|---|
| 1 | WIKI | 1,457 | 2,434 | 837 | 4.4 | 1,794 | 1,680 | 586 |
| 2 | GEOS | 97 | 547 | 320 | 1.0 | 165 | 26 | 3 |
| 2 | SIS | 265 | 477 | 199 | 0.2 | 67 | 104 | 7 |
| 2 | OAC | 64 | 77 | 57 | 0.2 | 52 | 61 | 22 |
| 3 | SURV | 1,494 | 7,344 | 6,323 | 98.7 | 1,811 | 617 | 133 |
| 3 | LABO | 577 | 1,383 | 1,705 | 9.5 | 1,770 | 456 | 21 |
| 3 | DOC | 601 | 983 | 383 | 1.3 | 283 | 131 | 81 |
| 4 | GSPP | 165 | 0 | 0 | 0 | 0 | 304 | 129 |
| 4 | SOIL | 127 | 268 | 201 | 0.3 | 16 | 2 | 1 |
| 4 | SSPT | 47 | 123 | 0 | 0 | 0 | 0 | 0 |
| 4 | ROV | 15 | 37 | 70 | 0.3 | 84 | 4 | 0 |
| 4 | ICT | 2 | 4 | 0 | 0 | 0 | 1 | 0 |
| 4 | LLE, FPS | 0 | 0 | 0 | 0 | 0 | 0 | 0 |
| | **all 14** | **4,911** | **13,677** | **10,095** | **115.9** | | | |

Jira keeps changing: the rehearsal's own counts (B7 step 12) are the ones to plan production with.

**Measured** (test instances, Redmine and staging on the same machine, `download_concurrency` 6; the logs per step
are in `runs/` of the test environment, not in this repository):

| run | issues | attachments | duration | longest steps |
|---|---|---|---|---|
| GEOS, SIS, OAC, first batch, 7.0 (5.1 the same) | 426 | 576, 1.5 GiB, all | 24 min (25 on 5.1) | 11 8 min, 14 3 min, 09 push 2.4 min, 10 2.3 min |
| WIKI, second batch after GEOS, SIS, OAC, 7.0 | 1,457 (1,883 in the run) | 837, 4.4 GiB, all | 76 min | 11 26 min, 14 13 min, 14 jira phase 8 min, 10 11 min, 09 7 min, 13 4 min |
| SURV alone, 5.1, with the sample | 1,494 | 4,306 of 6,323 sampled: 0.98 GB of 98.7 GiB | 80 min | 11 43 min, 14 11 min, 09 12 min, 10 8 min |

Those runs took 0.5 to 1.2 s per issue plus comment (SURV the fastest per item, WIKI the slowest); 11 took 0.35 to
0.65 s per comment, 14's jira phase about 0.25 s per issue (459 s for 1,883 issues), 16 reads every Jira workflow
(1.5 min), 08's transform 2 min. In one run those reads happen once, not once per batch.

**Attachments without the sample** (production): pull measured at 18 to 27 MB/s, upload at 11 to 18 MB/s into a
local Puma and 33 MB/s behind Apache with X-Sendfile (docs/RUNBOOK.md, appendix). The 116 GiB then take about 1.3 to
1.9 hours to pull and 1 to 3 hours to upload, depending on the server's network.

**Measured: the one run of the 14 projects** (test instance 7.0, 2026-10-15/16, on a Redmine that is not empty,
`run_all.sh` with the procedure of B7 and B9, `test_sample`, 4,917 issues, 13,700 comments, 6,852 sampled files of
1.1 GiB; Redmine and staging on one machine, no reviews, snapshots marked but not taken):

| part | measured | production adds |
|---|---|---|
| S0 to S1: 01 to 08 (B7 steps 1 to 5) | 45 min: 14's jira phase 29 min, 08's transform 8 min, 10's jira phase 3 min, the rest under 3 min | Jan's reviews (B6, the memberships) |
| S1 to S2: 09 and 10 | 47 min: 09 pull 13 min, push 4 min; 10 29 min (create and final update), its second run 1 s | 2.3 to 5 hours for 116 GiB |
| S2 to S3: 11 to 16 | 2 h 41 min: 11 111 min, 14 41 min (29 of them its second jira phase, gone since `97c8a53`), 13 4 min, 12 2 min, 16 2 min, 15 1 min | |
| after S3: counts, verify, B9 (relink check, workflows, restore and its proofs) | 16 min | Jan's workflow review |
| run time | **4 h 28 min; about 4 hours without the second watcher read** | **about 6.5 to 9 hours** |

Production's figure: the measured 4 hours plus the attachments (the 116 GiB instead of the 1.1 GiB of the sample, B8
above), on a server whose speed is not known yet: the rehearsal measures it. All 14 projects ran in that test, DOC,
LABO, ICT, LLE, SOIL, SSPT, ROV, GSPP and FPS for the first time.

**The window** (decision 36): the run time above, plus Jan's reviews (B6, the memberships, the workflow review
tables) and the four snapshots (S0 to S3), whose duration depends on the hypervisor and the size of `files/`. The
rehearsal reports the real figure: the time from B5 to the end of B9, with the run time, the review time and the
snapshot time apart.

**In tmux:** the whole run, or at least 09 (pull and push), 10, 11 and 14. A step that is interrupted can be run
again (B10).

### B9. After the run

1. **The relink check** (decision 39: in one run the relink is only the check):

   ```bash
   php tools/relink_issue_links.php   # preview, writes nothing
   ```

   The last two lines must read `Preview (no --confirm-push): 0 relinked, ...` and `Texts that still hold a Jira
   link to a migrated issue after this run: 0`. Why 0: 11 pushes every comment when every issue exists, and 10's
   second pass rewrites the descriptions that link issues created later in the run. Measured in the test run of the
   14 projects: 570 texts with a Jira issue link, 0 to relink, 0 left (with `e1cfa33`; before it, B76 reported 53).
   A `[would relink]` line means a
   text pushed before its target existed (a rerun after a partial failure, or the fallback of B11): then **GO**,
   `php tools/relink_issue_links.php --confirm-push` (through the Extended API with notify=false and `skip_journal`:
   no mail, no webhook, no journal, the issue keeps its updated time, a rewritten comment keeps its times and is not
   marked "Edited"), and the preview again until it says 0. A text listed as `[left]` was
   edited in Redmine since the migration: fix it by hand, run the check again. Also: 12's MANUAL relations and 10's
   `[waiting]` parents name only projects that are not migrated (TEST).
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

   `workflow_plan.php` names the trackers whose global workflow stays as it is (decision 34). The last preview must
   say `transitions +0 -0` and `waiting for review: transitions 0`.
3. **Restore** (Redmine server, Redmine root): takes away the workflow transitions the `workflows` step opened (not
   those the runner applied), the role `Migration (temporary)` and the memberships left without a role, and locks
   the activated users again.

   ```bash
   J2R_STEP=restore RAILS_ENV=production bundle exec rails runner <checkout>/tools/testenv/manual_steps.rb
   ```

   Output: `workflow transitions removed=N temporary member roles removed=N members=N users locked again=N`; `users
   locked again` equals `activated` of the `members` step (B7 step 4). Then the workflow runner's preview once more:
   still `+0 -0`. Rehearsal: also `RAILS_ENV=production bundle exec rails runner
   <checkout>/tools/testenv/temporary_role_proof.rb` (with the run's `J2R_PROJECTS`; read-only: a rolled-back
   transaction) and `php tools/testenv/membership_check.php` (`memberships equal 04's proposal: yes`; on a project
   that existed before, its older memberships show as differences, B6).
4. **Settings back on and Redmine open** (production; on the clone the crontab stays off): stealth mode off for the
   migration account (`toggle=false`, answer `{"is_cloaked":false}`), the checklists setting and the webhooks as they
   were if B5 changed them, the saved crontab back (`crontab <saved file>`), then **open Redmine**: the maintenance
   rules and the local entry out of the web server (B5), reload it, check a login from another machine, and Jan
   announces that everyone works in Redmine. Jira stays read-only. Only `restore` and the checks of steps 1 to 3 come
   before this; staging (step 6) can go later. Leave cross-project subtasks and relations on (B5).
   **Never run `rake redmine:watchers:prune`** afterwards: it deletes the historical watchers the migration kept on
   purpose (decision 12).
5. **After the run, by hand:** retire the `[unoffered]` select values (docs/RUNBOOK.md, section 2); rebuild what the
   automation rules export lists (custom workflows, recurring tasks). **Jan sees** the relations Redmine refused,
   each with Redmine's reason; the test run had one, which production will show too: DOC-449 "relates to" DOC-448
   while DOC-448 "blocks" DOC-449, refused by Redmine as "This relation would create a circular dependency". The Jira
   link stays in Jira; Jan decides whether anything is added by hand.

   ```bash
   mysql jira2redmine -e "SELECT r.jira_link_id, a.jira_issue_key, r.proposed_relation_type, b.jira_issue_key, r.notes
     FROM migration_mapping_issue_relations r
     LEFT JOIN migration_mapping_issues a ON a.redmine_issue_id = r.redmine_issue_from_id
     LEFT JOIN migration_mapping_issues b ON b.redmine_issue_id = r.redmine_issue_to_id
     WHERE r.migration_status IN ('CREATION_FAILED', 'MANUAL_INTERVENTION_REQUIRED')"
   ```

   MANUAL rows are links whose other issue is not migrated (2 in the test run).
6. **Keep staging as Jan's encrypted dump, then remove the database** (Jan, decision 41), once no rerun is needed.
   Staging is the archive of everything Jira had that Redmine does not show (11 wrote only the status, assignee and
   priority history, decision 41; docs/research/JIRA-HISTORY.md lists the rest). The whole database is dumped (it holds at least
   `staging_jira_changelogs` and the mapping tables for users, statuses, priorities and issues), encrypted and read
   back before anything is dropped. It holds Jira data: never in the repository, never in a chat. **Jan** owns the
   dump: it stays on the production server, Jan moves and secures it himself, and decides after the handover when to
   delete it. Jan types the passphrase; it is never written to a file, a log or the chat.

   ```bash
   set -o pipefail   # a failed mysqldump must stop here, not leave a small encrypted file
   D=<dump-dir>   # on the server, outside the checkout, readable by the operator only (chmod 700)
   F="$D/jira2redmine-$(date -u +%Y%m%d).sql.gz.gpg"
   mysqldump --single-transaction --quick --hex-blob --default-character-set=utf8mb4 jira2redmine \
     | gzip | gpg --symmetric --cipher-algo AES256 -o "$F"      # Jan types the passphrase
   # read it back before anything is dropped: decrypt into a scratch database and compare the counts
   mysql -e "CREATE DATABASE jira2redmine_check CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   gpg --decrypt "$F" | gunzip | mysql jira2redmine_check  # Jan types the passphrase again
   for t in staging_jira_changelogs migration_mapping_users migration_mapping_statuses migration_mapping_priorities \
            migration_mapping_issues migration_mapping_journals; do
     echo "$t $(mysql -N jira2redmine -e "SELECT COUNT(*) FROM $t") $(mysql -N jira2redmine_check -e "SELECT COUNT(*) FROM $t")"
   done
   mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'jira2redmine';
     SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'jira2redmine_check'"
   mysql -e "DROP DATABASE jira2redmine_check"
   ls -l "$F"   # tell Jan where the dump is and its size, so he can move it
   ```

   Check: each line shows the same count twice, and both databases have the same number of tables. **GO** (Jan)
   before anything is dropped. Then remove the staging database from the server: `mysql -e "DROP DATABASE
   jira2redmine; DROP USER 'jira2redmine'@'localhost';"`, `apt purge mariadb-server` if it was installed for this,
   empty `$J2R_TMP`, delete the environment file with the secrets, and ask Jan to revoke the Jira token. The dump `$F`
   stays where it is for Jan. The rehearsal practises this step on the clone's staging (the read-back included) and
   then deletes its dump.

### B10. When something fails: rerun or roll back

- **Rerun first.** Every script can run again: migrated rows are skipped, FAILED and MANUAL rows are judged again,
  rows edited by hand keep the edit (automation hash). Find the reason in the log and in the `notes` column of the
  mapping table, fix the cause (a missing relation type, a full disk, a network error), run the same step again,
  then the steps after it. Proved on 5.1 and 7.0: a second run of 08 to 15 changes nothing in Redmine.
- **A Jira error** (502, 503, 504, 429) is retried by the scripts (decisions 5 and B71) for reads; if a jira phase
  still stops, rerun it.
- **Inside the window or after it** (decision 36): while Redmine is closed, a rollback to a snapshot costs no user
  work. Once Redmine is open only a fix forward is left (a rerun, or a fix by hand), since a rollback would erase
  what users did.
- **Roll back** (the upgrade itself: A.7) when a step left Redmine in a state a rerun cannot repair (a wrong match
  pushed, B6; data in an existing project that should not be there): restore the last snapshot before the failing
  step, then run again from there.

  | failure in | roll back to | run again from |
  |---|---|---|
  | B7 steps 1 to 5 (structure, workflows, memberships, members, custom fields) | S0 | B7 step 1 |
  | B7 steps 7 and 8 (09, 10) | S1 | B7 step 7 |
  | B7 step 10 (11 to 16) | S2 | B7 step 10 |
  | B9 steps 1 to 3 (relink, workflows, restore) | S3 | B9 step 1 |

  Each snapshot must hold Redmine's database, Redmine's `files/` and `tmp/j2r_added_workflows.json`, **and the
  staging database**: staging records what was pushed, so a Redmine rolled back without its staging would make the
  next run skip issues that are no longer there. With staging on the same VM (B2) one snapshot holds all of it.
  `paths.tmp` is empty at S1 (nothing pulled yet) and at S2 (emptied after 09's push), so no snapshot carries a
  second copy of the attachments. Without VM snapshots: a `pg_dump` of Redmine, a copy of `files/`, a copy of `tmp/j2r_added_workflows.json`
  (when it exists), and a `mysqldump` of staging, taken at the same moment and restored together: a Redmine restored
  without its list, or with a list of another moment, makes `restore` remove the wrong transitions, those Redmine had
  before the migration included (B82; the `workflows` step now stops on a list that does not match its Redmine).
- **Do not hotfix the scripts on the server.** A defect: stop, write down the step, the log lines and the mapping
  rows, and report it (rehearsal: through Jan to the coordinator and B2; production: roll back and wait for a fixed
  `main`).

### B11. Fallback: four batches

Only when Jan chooses it (decision 39 keeps it as the fallback): the four batches of decision 22, each its own
pass of B7, still inside the one window of decision 36.

| batch | new projects | `J2R_PROJECTS` |
|---|---|---|
| 1 | WIKI | `wiki` |
| 2 | GEOS, SIS, OAC | `wiki,geos,sis,oac` |
| 3 | SURV, DOC, LABO | `wiki,geos,sis,oac,surv,doc,labo` |
| 4 | ICT, LLE, SOIL, SSPT, ROV, GSPP, FPS | `wiki,geos,sis,oac,surv,doc,labo,ict,lle,soil,sspt,rov,gspp,fps` |

What differs from the one run:

- `J2R_PROJECTS` holds **every project migrated so far plus the batch's new ones**: the jira phases of 07 and 08 read
  only the run's projects and replace their staging tables, so a batch with the new projects alone would narrow what
  08 knows of the earlier ones. Migrated issues, journals and attachments are skipped on the rerun. The order puts
  first what others link to: GEOS issues hang under WIKI epics.
- A **snapshot before every batch** instead of S1 to S3; a failure rolls back to the snapshot of its batch.
- B6's review: every match in the first batch, the new rows in later ones. The `members` step runs in every batch;
  `restore`'s `users locked again` then equals the sum of `activated` of the four.
- An issue whose parent is in a later batch waits (`[waiting]`, READY_FOR_REWRITE), and a relation whose other side
  is in a later batch stays MANUAL ("Missing Redmine issue mapping"): the batch that migrates that side links them.
  Check per batch: those lines name only projects of later batches.
- `paths.tmp` is emptied after each batch's 09 push; the third batch (SURV, LABO, DOC, 109.5 GiB) needs about 110
  GiB in `paths.tmp` and as much in `files/`.
- After the last batch B9 step 1 is a real **relink**: preview, then `--confirm-push` (**GO**), then the check, whose
  last line must be 0. It rewrites links to issues a later batch migrated.
- Estimates per batch (test instances, rehearsal with the sample; production adds the attachment time): WIKI about
  1 hour 15 min (4.4 GiB, measured with all files); GEOS, SIS, OAC about 40 min; SURV, LABO, DOC about 2 to 3 hours,
  in production plus 2 to 5 hours for 110 GiB; the fourth batch about 40 to 60 min. 14's jira phase, 16 and 08's
  transform read every project migrated so far in every batch: together about 30 to 60 minutes more than one run.

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
   but GET requests to Jira. The passphrase of the staging dump (B9 step 6) is typed by Jan: never ask for it in
   the chat.
2. Stay in the default permission mode.
3. Follow part A, then part B, in order, one step at a time: part B is one run of all 14 projects (B7); the
   batches of B11 only when Jan says so. On the clone, check A.1 before Redmine or cron starts. Before a step say
   what it does; after it quote its summary lines and the check the document gives. Never call something green
   you did not see green.
4. Wait for Jan's explicit go at every point marked GO and at the go or rollback decision of A.7. In production
   also before every step of A.4 from step 3 on, every --confirm-push, --confirm-pull, relink --confirm-push and
   every rails runner step (manual_steps.rb workflows, members and restore; apply_workflows.rb --confirm-push),
   and run the --dry-run of every push first.
5. Production: the upgrade does not start without the snapshot of A.4 step 3, part B not without S0 (B5), and
   no part of the run without the snapshot before it (S1 to S3, B7 and B10), each confirmed by Jan. Part B runs in
   one maintenance window (B5): before 01 Jan confirms that Jira is read-only for users, check that Redmine answers
   503 from outside and 200 on the local entry, and that the crontab is off; Redmine opens only after restore and
   the checks (B9). Before 01 and after every snapshot check B5's "no mail" row: the Extended API enabled, the
   checklists setting off, stealth mode on for the migration account.
6. Run 09, 10, 11 and 14 in tmux. Log every step with tee into <log-dir>, outside the checkout.
7. Change no code and no config default of the migration scripts, and nothing in Redmine beyond what the steps
   of this document say. In staging, edit only the review tables Jan asks you to (B6, 04's memberships, the
   workflow review tables) and only as he states, and write each edit down as SQL in the report: staging is new
   in production, so the edits must be replayed there (B6). On a failure: stop, show the log lines (and the
   mapping rows), and propose a rerun or a roll back (A.7, B10); Jan decides.
8. The repository is public: no commit, no push, no pull request from this server. Logs, verify reports and
   anything holding Jira data stay in <log-dir>.
9. Never run rake redmine:watchers:prune, never clear Redmine's tmp directory before restore, never send mail.
10. Report in Dutch, short, after part A (A.5 row by row, the duration for A.8), at S1, S2 and S3 and at the end:
   per step the result and its duration, each snapshot's duration, the counts of B7 step 12, verify, every
   failure with its reason, what Jan reviewed and decided, and everything unexpected, above all matches against
   objects the Redmine already had (B6).
```

In the rehearsal the session's findings go to Jan, who passes them on to the sessions that maintain the scripts
and the upgrade; production waits until they are fixed on `main`.
