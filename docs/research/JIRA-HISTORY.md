# Jira status history in Redmine: research

Research only (2026-10-19): no script, plugin or core was changed. The question: project managers want to see in
Redmine which Jira status changes (and other field changes) happened on an issue, by whom and when. Jan's principle:
an issue in Redmine matches Jira as closely as possible, dates, journals and authors included.

Code read: jira2redmine_migration `redmine70-migration` (`1579174`), redmine_extended_api `redmine70-migration`
(`de4ab98`), redmine `7.0-stable-GEOxyz` (`7310d11`) and `5.1-stable-GEOxyz` (`bcf917c`, only the files named
below), redmine_agile `redmine70-migration` (`0b57a01`), and 17 other plugins of the 44-plugin set (their
`redmine70-migration` branches, read by a sub-agent; list in "Other plugins").

## Summary

- **Volume** (all 14 projects, measured today): 4,932 issues, **51,261 history entries with 59,733 items**. Of
  those entries, 4,073 name an attachment (11 already pushes them as journals); **47,188 are skipped today**. Status
  items: **14,391**; assignee 6,637; resolution 3,037; priority 445; fix version, component and sprint: **0**.
- **Status mapping is clean apart from LABO**: in 13 projects every status in the history is one 05 stages, so it
  has a Redmine status. In LABO, 610 status items (24 % of LABO's 2,489) use **7 Jira statuses that no project uses
  any more**; 05 skips those, so they would have no Redmine status. For every issue with a status change (4,145)
  the last change ends in the issue's current status (0 mismatches).
- **Option A (real journals)** gives the best result in Redmine itself: the history tab, the activity stream, and
  redmine_agile's cumulative flow chart, status-time tab and "days in state" become correct. Today these charts are
  wrong for migrated issues, because every issue counts in its current status from its creation onwards (measured,
  below). It needs a **new admin-only Extended API endpoint**, because the plugin cannot create a journal with
  details today (only through a real issue update, which changes the issue).
- **Option B (one JSON file per issue)** is cheap, but **leaks** history of fields that redmine_issue_field_visibility
  hides per role (assignee, priority, due date, description...); it is not searchable (Redmine searches attachment
  names, not contents), it adds an attachment to at least 4,145 issues, and no Redmine view or plugin can use it.
- **Recommendation**: a hybrid. **A for status, assignee and priority** (the fields the charts and the status tab
  read), as a separate step after 11, through a raw-insert endpoint; **no B attachment**; **keep the staging database
  as an archive** for everything else and as the source to rebuild or extend the history later. A is not needed for
  the rehearsal and can be added after production, **if the staging database is kept** (B9 now removes it). That one
  point must be settled before production.

## 1. Volume and content

**How measured.** A throwaway PHP script (not committed) with the scoped read token through the gateway. The token
was proven read-only first: the write probe of PRODUCTION.md B4 (PUT on an issue that does not exist) answered
**401**, and `GET /rest/api/3/myself` answered 200. GET only: `GET /rest/api/3/search/jql` (fields status, created,
comment) and `GET /rest/api/3/issue/{id}/changelog` (paged, 100 per page) for every issue; 3,046 calls in two runs
(the first stopped on a network error after SURV; the other nine projects were then run separately). The script
writes aggregates only. `POST /rest/api/3/changelog/bulkfetch` was **not** used (it is a read through POST, needs
Jan's consent; it would cut the calls to about 50).

**Per project** (entries = Jira history entries, one per save; items = field changes inside them; "authors" counts
distinct authors per project, overlapping between projects):

| project | issues | entries | items | status | assignee | priority | resolution | entries with attachment | entries per issue p50 / p95 / max | authors (people active / inactive / apps) | entries without author |
|---|---|---|---|---|---|---|---|---|---|---|---|
| WIKI | 1,457 | 15,465 | 16,237 | 4,300 | 2,884 | 48 | 469 | 243 | 7 / 31 / 212 | 60 / 15 / 1 | 1 |
| GEOS | 99 | 814 | 1,004 | 369 | 107 | 7 | 101 | 52 | 7 / 18 / 25 | 44 / 4 / 1 | 0 |
| SIS | 266 | 1,542 | 1,769 | 651 | 262 | 48 | 103 | 95 | 5 / 11 / 21 | 20 / 1 / 2 | 0 |
| OAC | 64 | 770 | 1,122 | 80 | 76 | 2 | 35 | 52 | 7 / 34 / 39 | 7 / 0 / 1 | 0 |
| SURV | 1,501 | 14,289 | 17,446 | 4,152 | 1,565 | 223 | 1,531 | 2,316 | 8 / 20 / 88 | 108 / 22 / 3 | 0 |
| DOC | 605 | 8,538 | 10,359 | 1,901 | 980 | 72 | 186 | 575 | 12 / 34 / 118 | 41 / 6 / 2 | 231 |
| LABO | 584 | 6,333 | 6,978 | 2,489 | 586 | 31 | 453 | 515 | 10 / 23 / 37 | 38 / 6 / 1 | 0 |
| ICT | 2 | 14 | 16 | 6 | 2 | 0 | 2 | 0 | 7 / 7 / 7 | 4 / 1 / 0 | 0 |
| SOIL | 127 | 1,381 | 1,956 | 332 | 90 | 12 | 116 | 155 | 10 / 20 / 40 | 9 / 1 / 2 | 0 |
| SSPT | 47 | 714 | 1,309 | 64 | 63 | 2 | 17 | 2 | 15 / 22 / 27 | 5 / 0 / 1 | 0 |
| ROV | 15 | 107 | 116 | 16 | 12 | 0 | 8 | 68 | 5 / 8 / 50 | 8 / 0 / 0 | 0 |
| GSPP | 165 | 1,294 | 1,421 | 31 | 10 | 0 | 16 | 0 | 4 / 22 / 27 | 5 / 0 / 1 | 0 |
| LLE, FPS | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | | | |
| **all** | **4,932** | **51,261** | **59,733** | **14,391** | **6,637** | **445** | **3,037** | **4,073** | | | **232** |

Check against the plan: GEOS, SIS and OAC have 3,126 entries, 199 of them with an attachment, so 2,927 would be
skipped; the plan's 5.1 and 7.0 runs reported 2,950 skipped for the same three projects (Jira has changed since).

**Items per field, all projects** (Jira's field names; "custom" by Jira field type):

| field | items | maps onto in Redmine |
|---|---|---|
| status | 14,391 | `status_id` (attr) |
| assignee | 6,637 | `assigned_to_id` (attr) |
| Attachment | 5,284 | already pushed as journals with "> attachment:" notes (4,073 entries) |
| Link | 4,338 | `relation` details (12 creates relations with `skip_journal`, so no relation journal exists today) |
| custom textarea | 4,215 | custom field (`cf`), long text |
| custom select | 3,642 | custom field (key/value list; ids per value) |
| resolution | 3,037 | custom field "Resolution" (10 `appendResolutionCustomFieldPayload`) |
| description | 2,274 | `description` (full old and new text) |
| checklist text (app field) | 2,094 | redmine_checklists (15); its own history, not a journal detail |
| summary | 1,790 | `subject` |
| parent (IssueParentAssociation 1,619, Parent 214) | 1,833 | `parent_id`, only when both issues are migrated |
| labels | 1,462 | redmineup_tags `tag_list` (attr) |
| custom url | 1,397 | custom field |
| label manager (app field) | 1,283 | 13's tags |
| custom textfield | 1,109 | custom field |
| custom cascading select | 1,070 | custom field (08's mapping of the two levels) |
| issuetype | 581 | `tracker_id` |
| rank (gh-lexo-rank) | 529 | none (Jira board order) |
| datepicker 470, duedate 468 | 938 | custom field / `due_date` |
| reporter | 452 | `author_id` (core never journals it; redmine_editauthor does) |
| RemoteIssueLink 173, RemoteWorkItemLink 90 | 263 | none |
| Comment | 159 | none (comment deleted or edited; the comment itself is 11's journal) |
| float | 153 | custom field |
| project 87, Key 87 | 174 | issue moved between Jira projects: `project_id` only if both are migrated |
| custom field that no longer exists | 46 | none |
| multi checkboxes | 41 | custom field |
| security | 26 | none (issue security level) |
| time tracking (estimate 14, spent 13, worklog 13, original 3) | 43 | none (decision 24) |
| team, epic fields | 26 | none |

57 distinct custom fields appear in the history.

**Authors.** Per project 0 to 3 Jira apps (automation and the like) and 0 to 22 inactive accounts. 232 entries
have no author at all (231 in DOC). Today 02 gives an app no Redmine account (B3) and 11 writes such content as
`J2R_DEFAULT_AUTHOR_ID`. A deactivated account maps if 02 found its e-mail. **Not measured**: the number of entries
per author type, and how many inactive authors 02 actually maps (that needs the staging tables of a run).

**Same author, same moment as a comment**: 771 entries have the same author and the same second as one of the
issue's comments; 6,250 lie within 60 seconds of one. (The search returns at most part of the comments for 65
issues with many comments, so both numbers are slightly low.) **Entries within one second of the issue's creation**:
1,762, mostly values Jira sets right after the create (parent link, rank, attachments).

**Size as JSON** (one array of `[time, author, field, from, fromString, to, toString]` per issue): 18.3 MiB for all
projects; per issue p50 0.5 to 14 KiB depending on the project, **largest 3.0 MiB** (a WIKI issue whose description
was rewritten many times: every item carries the full old and new text). Without description and long text fields:
**not measured**.

## 2. Option A: real Redmine journals

### 2.1 Can the Extended API create journal details today? No.

- The plugin's routes add four `GET` endpoints and proxy everything else to core (`config/routes.rb`,
  `proxy_app.rb`). Core has no API that creates a journal; `PUT /journals/:id` only edits notes.
- `journals/show.api.rsb` **renders** details, but only for the journal an issue update has just written
  (`IssuesControllerPatch#render_api_ok`).
- The only way to get a `JournalDetail` today is a real issue update (`PUT /extended_api/issues/:id` with
  `status_id` and the journal overrides `user_id`, `created_on`). That changes the issue: replaying the history this
  way means creating every issue in its first state and walking it through every change, through workflow
  checks, closed parents, required fields and `closed_on` recalculation. That is a rewrite of 10, not an addition.

**Smallest addition (design only)**: `POST /extended_api/issues/:id/history.json`, admin only, like `skip_journal`.

```json
{ "notify": false,
  "journals": [
    { "user_id": 12, "created_on": "2024-03-01T10:15:00Z",
      "details": [ { "property": "attr", "prop_key": "status_id", "old_value": "1", "value": "3" },
                   { "property": "attr", "prop_key": "assigned_to_id", "old_value": null, "value": "12" } ] } ] }
```

- One request per issue, all of its history entries; the answer lists the new journal ids in order (11's mapping
  table `migration_mapping_journals` already has `entity_type = 'CHANGELOG'` rows to keep them, so a rerun skips
  what is pushed).
- Refused (403) for a non-admin, refused (422) for an unknown `property`, a `prop_key` that is not an issue column
  or a custom field of the issue, or a user that does not exist. No notes accepted (comments stay 11's).
- Written with `Journal.insert_all` and `JournalDetail.insert_all` in one transaction, **not** `Journal#save`.
  Reason: every journal callback is then skipped, and several do harm here (measured or read, below): core's
  `add_watcher` makes the journal's author (and the assignee) a watcher, `send_notification` mails,
  redmine_ai_summary starts an AI job per journal, redmine_contacts_helpdesk saves the helpdesk ticket. The issue is
  not saved, so `updated_on` stays (decision 37) and no webhook fires (7.0's webhook lives on `Issue`).
- Same code on Redmine 5.1, 6.1 and 7.0: `Journal`, `JournalDetail` and `insert_all` (Rails 6+) are the same there.
- Tests: per case a request test (journal and details stored with the given user and time; issue `updated_on` and
  watchers unchanged; no mail; 403 non-admin; 422 per invalid input).

### 2.2 Status mapping

- 05 matches a Jira status to a Redmine status by name (case-insensitive), else proposes to create it. The 39
  statuses Jira lists have 39 distinct names, so no many-to-one arises by itself; it arises only where Jan maps two
  Jira statuses onto one Redmine status by hand. Many-to-one is harmless for A: the detail shows the Redmine name,
  and a change between two Jira statuses that map onto the same Redmine status becomes "from X to X", which A should
  drop (Redmine never stores such a detail).
- **Unmapped: LABO's 7 statuses** (610 items). They exist in Jira (`GET /rest/api/3/statuses?id=` answers them, all
  global) but no project uses them (`projectUsages` 0 for each), so 05 skips them (B57 logic: "used by none,
  skipped"). Choices: (a) 05 also stages statuses seen in the history, so they become Redmine statuses that no
  workflow uses; (b) a mapping table onto existing Redmine statuses that Jan fills (lossy); (c) drop those details
  and join the chain (A to X to B becomes A to B at the time of X to B; the time spent in X is lost).
  A detail with an id that is no Redmine status is visibly wrong: the history shows the raw number, agile's
  cumulative flow drops the issue for those days, and the status tab shows a group "id 999999" (measured, 2.5).
- **The last detail agrees with the issue**: 4,145 of 4,145 issues with a status change end in their current status;
  787 issues never changed status. A check in the script (last `value` = the issue's `status_id`) is still worth it.

### 2.3 Other fields

| maps cleanly | needs work | does not map |
|---|---|---|
| `status_id`, `assigned_to_id` (users and groups through 02/03), `priority_id` (decision 35's mapping), `subject`, `due_date`, `tracker_id` (07) | custom fields: select and cascading values must be translated to 08's value ids, retired values (B37) included; `parent_id` and `relation` only when both issues are migrated; `tag_list` (labels; 13 and redmineup_tags) | rank, sprint (0 items anyway), time tracking (decision 24), security level, remote links, the 46 items of deleted custom fields, team and epic fields, checklist text (redmine_checklists keeps its own history) |

**Description and long text fields**: possible (core shows "Description updated" with a diff), but each old text must
go through the text conversion (decision 7, 26), and the history then shows old versions of texts, including text
that someone removed on purpose. Not recommended.

### 2.4 Separate journals or merged with a comment?

- Redmine orders the history by `created_on, id` (7.0 `Issue#visible_journals_with_index`, `reorder(:created_on,
  :id)`; 5.1 the same at `issue.rb:932`). Journals inserted after 11's comments still appear at their Jira time;
  on the same second the comment (lower id) comes first. Measured on 7.0 below.
- Merging is possible only by appending details to an existing comment journal (another endpoint mode) and only for
  the 771 exact matches. Jira itself shows a comment and a field change as separate entries (Comments and History
  tabs). **Separate journals**; merging adds code for little.

### 2.5 Side effects, measured on a throwaway Redmine 7.0

A local `7.0-stable-GEOxyz` (`7310d11`) with redmine_agile (`0b57a01`), PostgreSQL 16, synthetic data in a
transaction that was rolled back. One issue created 60 days ago, now in a closed status, one comment on day 20; then
three detail-only journals (day 10 new to in progress, day 20 in progress to done, day 30 done to an id that is no
status), inserted without saving the issue:

| | before the history | after |
|---|---|---|
| issue `updated_on` | | unchanged |
| history tab order | | day 10 status, day 20 comment, day 20 status, day 30 status |
| rendered details | | "Status changed from XNew to XProgress", ..., "from XDone to 999999" |
| agile cumulative flow, issues per status on day 1 / 25 / 55 | done 1 / 1 / 1 | new 1 / 0 / 0, done 0 / 1 / 0 (day 55: the unmapped id, **the issue disappears**) |
| agile status tab (time per status) | done 60 days | new 10, in progress 10, done 10, "id 999999" 30 |
| agile "days in state" | since creation | since day 30 |
| activity stream | the issue | plus the three journals (status changes shown as "issue-edit" or "issue-closed") |
| mail | | 0 deliveries (`notify = false`) |

**Watchers** (separate check, rolled back): a detail-only journal written through `Journal#save` by an active member
with "Add watchers" and the default preference (`issue_created`, `issue_contributed_to`) made that member a watcher.
The plan already saw this for 11's comments (watchers 1,140 in Redmine against 960 from Jira in the GEOS, SIS, OAC
run). With A through `save`, every history author would become a watcher: the reason for `insert_all` in 2.1.

**Issue page speed** (test environment, local Puma, synthetic, average of 3 loads): 0 journals 58 ms, 100
detail-only journals 310 ms (198 KiB), 400 journals 683 ms (746 KiB). The largest issue has 212 history entries;
p95 is 11 to 34 per project. Production mode with caching: **not measured**.

**redmine_agile** (code read, `redmine70-migration`):
- cumulative flow (`charts/cumulative_flow_chart.rb:33-46`) takes, per day, the last `status_id` detail up to that
  day, else the first detail's old value, else the current status: without history every migrated issue counts in
  its current status since its creation, **wrong today**; with A correct, provided every value is a Redmine status;
- status tab (`AgileStatusesCollector`, `AgileJournalDetailsController`) and the board's "days in state"
  (`Issue#day_in_state`): the same;
- cycle time and lead time (`charts/cycle_time_chart.rb`) use `created_on` and `closed_on` only (10 sets
  `closed_on` from Jira's resolution date): **not affected** by A;
- done ratio charts read `done_ratio` details: Jira has no such field, unaffected.

**Other plugins** (code read by a sub-agent, `redmine70-migration` branches):
- redmine_issue_field_visibility filters journal details per viewer (`Journal#visible_details`), the same as for
  live changes; a journal whose details are all hidden disappears for that viewer. **No leak in core views.** It can
  hide `assigned_to_id`, `category_id`, `fixed_version_id`, `start_date`, `due_date`, `estimated_hours`,
  `description`, `priority_id`; not `status_id`.
- redmine_ai_summary: `after_create` starts a summary job per journal when its auto-generate is on (default off);
  its summaries read `journal.details`, not `visible_details`, so hidden fields can reach summary text (already true
  for live journals). `insert_all` skips the callback.
- redmine_contacts_helpdesk: `after_create` saves the issue's helpdesk ticket and its reaction time takes the first
  journal; only issues with a helpdesk ticket (migrated issues have none). `insert_all` skips the callback.
- redmine_people: the user activity report counts the journals at their Jira dates (true history).
- redmine_checklists, redmine_stealth, redmineup_tags, redmine_editauthor, redmine_ai_triage, redmine_mail_digest
  (selects by the issue's dates), redmine_reporter_dashboards, redmine_custom_workflows (a journal is not
  observable), redmine_inline_edit_issues, redmine_issue_view_columns, redmine_tint_issues, redmine_subtask,
  issue_recurring: no harm found.

**Search**: Redmine searches journal notes, not details: A adds nothing to search.

**Decision 37**: met by the raw insert (no issue save, no journal from post-processing, `updated_on` unchanged).
The journals A writes are content with their Jira author and date, like 11's comments, not a trace of the run.

### 2.6 Run time and calls (estimate, not measured)

- Jira: **0 extra calls**; 11 already reads every changelog into `staging_jira_changelogs`.
- Redmine: one request per issue with history: at most about 4,900. 11 took 0.35 to 0.65 s per comment in the
  measured runs; a request that inserts rows without saving the issue should be at the low end: **about 25 to 50
  minutes** added to the one run (now about 4 hours). Per entry instead of per issue: status, assignee and priority
  alone touch at most 21,473 items, so up to about 2 to 4 hours: batch per issue.

## 3. Option B: a JSON file per issue

**Format proposal** (`jira-history.json`, one per issue):

```json
{ "format": 1, "jira_issue_id": "10001", "jira_key": "ABC-1", "generated_at": "2026-10-19T10:00:00Z",
  "entries": [
    { "jira_history_id": "20001", "created": "2024-03-01T10:15:00.000+0100",
      "author": { "jira_account_id": "557058:...", "redmine_login": "jdoe", "redmine_user_id": 12 },
      "items": [ { "field": "status", "field_id": "status", "from": "1", "from_string": "Open",
                   "to": "3", "to_string": "In Progress", "redmine": { "prop_key": "status_id", "old_value": "1", "value": "3" } } ] } ] }
```

- **Size**: 18.3 MiB for all projects with every field (largest file 3.0 MiB, description rewrites); smaller without
  long texts (not measured).
- **Visibility: a leak.** An attachment follows the issue (`Attachment#visible?` through the container); its
  content is not filtered per role. The history holds 6,637 assignee, 445 priority, 468 due date and 2,274
  description items, all fields redmine_issue_field_visibility can hide per role; a viewer for whom Jan hides them
  would read them in the file. Also in the file: 26 security level changes and old description text. Which roles
  GEOxyz's Redmine hides fields for: **not measured** (a setting of the existing Redmine; the rehearsal can read it).
- **Decision 37**: attaching through a later issue update writes an attachment journal; with `skip_journal` the
  journal is not stored and `updated_on` stays (JournalPatch aborts every new journal under the flag). **Not tested**
  with uploads; the plugin's tests cover description, parent and relation updates.
- **Search**: Redmine searches attachment file names and descriptions, not contents: not searchable.
- **Personal data**: Jira account ids are pseudonymous; display names are names of (former) staff. Propose the
  account id plus the mapped Redmine login, no display names.
- **Use later**: simple for a script (one file per issue, stable ids); no Redmine view, chart or plugin reads it.
  B can be turned into A later if it carries the Redmine mapping (the `redmine` part above), but the staging
  database (below) is a better source.
- **Clutter**: at least 4,145 issues (those with a status change; with any history: not counted) get an extra attachment in their file list.

## 4. Alternatives

| alternative | what | for | against |
|---|---|---|---|
| keep staging (a dump) | keep `staging_jira_changelogs` and the mapping tables (or the whole staging database) as an encrypted dump with limited access | 0 effort; complete raw history (`raw_payload`); the source to build A or anything else later | not visible in Redmine; holds all Jira data, personal data included: needs an owner and a retention period. PRODUCTION.md B9 now removes staging |
| a plugin table and a "Jira history" tab | a small plugin with its own table, its own permission ("view Jira history") and a tab on the issue | no effect on journals, activity, mail or other plugins; can hide per role | a new plugin for 5.1, 6.1 and 7.0 with tests, import code, upkeep; agile's charts stay wrong |
| one private note per issue with a table | 11 writes a private journal with a Markdown table | no new endpoint (11's comment path) | a visible journal at one moment, not the history; private notes show to every role with "view private notes", so the leak of B; long tables (212 entries) |
| hybrid: A for status (and assignee, priority), the rest archived | A for the fields that drive the charts and the status tab; staging kept for the rest | the visible value of A at a fraction of the mapping work; no leak (status cannot be hidden, assignee and priority details are filtered per viewer) | the other fields are only in the archive |

## 5. Recommendation and cost

**Recommended: the hybrid** (A for `status_id`, `assigned_to_id` and `priority_id`, raw insert; staging kept as the
archive; no B).

| | effort (estimate) | risk to rehearsal and production | reversible |
|---|---|---|---|
| Extended API endpoint (2.1) | 1 to 2 days with tests on 5.1, 6.1, 7.0 | none until used; a new endpoint, nothing existing changes | yes (the plugin version) |
| script step: a `history` phase after 11's push (or `tools/push_history.php`), reading staging, mapping users (02), statuses (05, plus the LABO choice), priorities (06); dry-run payloads; tests per mapping and per skip | 2 to 3 days | a new step, off by default; the existing steps unchanged | yes: delete the journals it wrote (their ids are in the mapping table) |
| proof run on 5.1 and 7.0 (journals per issue, last detail = current status, agile chart, 0 watchers added, `updated_on` unchanged) | 1 day | | |
| keep staging | a runbook line in PRODUCTION.md B9 (dump, encrypt, store, retention) | none | delete the dump |

- **Timing**: not needed for the rehearsal. A can run after production from a kept staging database: the journals
  sort by their own `created_on`, so writing them weeks later gives the same history; users' own changes after
  go-live come after them. The condition: **staging is kept** (at least `staging_jira_changelogs` and the mapping
  tables for users, statuses, priorities and issues). That choice must be made before production's B9. Building A in
  time for the rehearsal is possible and would test it on real data, but is not required.
- **Fuller A later**: custom fields (resolution first), parent and relation history can follow from the same staging
  data, each a separate change.

## What was not measured

Entries per author type and the inactive authors 02 maps; JSON size without long texts; production-mode page
speed; the actual run time of A (estimated from 11); B's attachment with `skip_journal` (not tested); the field
visibility settings of GEOxyz's Redmine; Redmine 5.1 behaviour beyond reading `issue.rb` and `journal.rb`
(same ordering, same callbacks, same activity provider).
