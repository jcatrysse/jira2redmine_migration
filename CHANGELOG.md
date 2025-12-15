# Changelog

All notable changes to this project will be documented in this file.

## [TODO]

- General: verify if all automation hashes align with the latest database schemas.
- Fine-tune the issues, and journals scripts.
- Migrate issues script:
    - on a rerun, newer issues should be fetched.
    - on transform, the script should ignore custom fields we didn't create in Redmine, based on the migration_mapping_custom_fields table.
    - investigate if we need to map the Jira system fields: resolution and resolutiondate to Redmine as a Custom Fields.
        - I manually added in the database, after the transform phase but before the push phase: INSERT INTO `migration_mapping_custom_fields` (`mapping_id`, `jira_field_id`, `jira_field_name`, `jira_schema_type`, `jira_schema_custom`, `jira_project_ids`, `jira_issue_type_ids`, `jira_allowed_values`, `redmine_custom_field_id`, `mapping_parent_custom_field_id`, `redmine_custom_field_enumerations`, `proposed_redmine_name`, `proposed_field_format`, `proposed_is_required`, `proposed_is_filter`, `proposed_is_for_all`, `proposed_is_multiple`, `proposed_possible_values`, `proposed_value_dependencies`, `proposed_default_value`, `proposed_tracker_ids`, `proposed_role_ids`, `proposed_project_ids`, `migration_status`, `notes`, `automation_hash`, `created_at`, `last_updated_at`) VALUES (NULL, 'resolution', 'resolution', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Resolution', 'text', NULL, NULL, '1', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'READY_FOR_CREATION', NULL, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP), (NULL, 'resolutiondate', 'resolutiondate', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Resolution date', 'date', NULL, '1', '1', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'READY_FOR_CREATION', NULL, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        - Ensure this logic fits the issue migration logic.
- Create the missing scripts: labels/tags, watchers, checklists, relations, subtasks, workflows, custom workflows...
- Validate we can push authors and creation timestamps to Redmine.
- re-enable: RAILS_ENV=development bundle exec rake --silent redmine:attachments:prune
- Test attachment deletion on duplicate attachments: example https://redminedev02.geoxyz.eu/attachments/8936 / https://redminedev02.geoxyz.eu/attachments/7217

## [0.0.70]

- Replace the Jira description conversion with a full HTML-to-Markdown pipeline
  (using rendered HTML as the source) that preserves CommonMark formatting,
  tables, and attachment links/inline images via the existing
  `league/html-to-markdown` tooling.
- Bump the issues migration script version to `0.0.32` to reflect the richer
  conversion and attachment handling.

## [0.0.69]

- Restrict issue custom field payloads to mappings in `MATCH_FOUND` or
  `CREATION_SUCCESS` status that already hold a Redmine custom field ID, and
  normalise values according to the mapped field format (including list
  enumeration lookups and cascading pairs). This explicitly includes system
  fields such as `resolution` and `resolutiondate` when added to the mapping
  table.
- Document the watcher migration approach as a dedicated post-issue step to keep
  issue creation lean and retryable.

## [0.0.68]

- Refresh SharePoint OAuth tokens automatically when upload sessions or
  chunked uploads encounter `401 Unauthorized` responses to avoid expiry
  failures during long-running attachment pushes.

## [0.0.67]

- Include atlassian-user-role-actor in the role assignment script.
- Bump the role migration script version to `0.0.13` to reflect
  the newly included actors.
- Introduce SharePoint offloading for oversized attachments with configurable
  OAuth credentials, target drive/folder, and a size threshold that skips
  Redmine uploads when set.
- Persist the SharePoint URL alongside attachment mappings and include those
  links automatically in issue descriptions or journal notes instead of
  uploading to Redmine.
- Bump the attachment migration script version to `0.0.19`, extend the staging
  schema with `sharepoint_url`, and declare the JSON extension dependency in
  `composer.json`.

## [0.0.66]

- Stream Redmine attachment uploads to avoid loading large files fully into
  memory when pushing binaries up to 2 GB via the REST API.
- Bump the attachment migration script version to `0.0.18` to reflect the
  upload pipeline change.
- Bump the role migration script version to `0.0.12` to reflect
  a payload correction for the Redmine push.

## [0.0.65]

- Populate enumeration values when creating Redmine custom fields through the
  extended API so select-list options are present immediately after creation.
- Prevent parent association updates from targeting mapping identifiers by
  resolving cascading parents to their Redmine IDs during the push phase.

## [0.0.64]

- Rename `migration_mapping_custom_fields.mapping_parent_custom_field_id` to
  reflect it stores the parent mapping identifier, resolving association lookups
  against the parent mapping's Redmine ID.
- Keep parent mapping identifiers immutable during the push phase while
  dependent field creation resolves parent Redmine IDs via mapping lookups.

## [0.0.63]

- Resolve custom field push warnings by sourcing project and tracker associations
  from the migration mapping table instead of the Redmine snapshot.
- Prefer the extended API `enumeration`/`depending_enumeration` formats over list
  payloads when creating custom fields, aligning dependent fields with the
  plugin expectations.
- Capture Redmine enumeration identifiers in the mapping table even when the
  initial create response omits them by reloading enumeration data after
  creation.

## [0.0.62]

- First attempt to implement the push phase for the custom fields migration script.
- Some issues remain to be resolved.

## [0.0.61]

- Prefer Redmine `enumeration` / `depending_enumeration` formats over the older
  list variants when proposing and creating custom fields, normalizing legacy
  values during push and manual review flows.
- Bump the custom field migration script version to `0.0.61`.

## [0.0.60]

- Capture Redmine enumeration IDs when creating list and depending list custom
  fields via the extended API, persisting them in
  `migration_mapping_custom_fields.redmine_custom_field_enumerations` for
  downstream mapping.
- Bump the custom field migration script version to `0.0.60`.

## [0.0.59]

- Refactor issues migration: restore cascading fields logic (untested)
- Resolve cascading parent custom field identifiers during the issue transform
  by following mapping links when Redmine IDs were stored as mapping
  references, ensuring cascading selections are only populated when both parent
  and child Redmine fields exist.
- Surface unmappable cascading selections as manual-review notes during the
  issue transform so push phase previews no longer rely on push-time cascading
  logic.
- Bump the issues migration script version to `0.0.30`.

## [0.0.58]

- Refresh cascading parent proposals during transform so tracker, role, project,
  and required/filter flags mirror the child field scopes even on reruns.
- Bump the custom field migration script version to `0.0.58`.

## [0.0.57]

- Link cascading child mappings to their generated parent entries by storing the
  parent mapping identifier when a Redmine custom field ID is not yet
  available.
- Bump the custom field migration script version to `0.0.57`.

## [0.0.56]

- Restore cascading fields logic for parents in the transform phase
- Fix transform-phase updates that wrote `IGNORED` statuses so they also bind
  `redmine_parent_custom_field_id`, avoiding SQL parameter errors when syncing
  cascading mappings.
- Bump the custom field migration script version to `0.0.56`.

## [0.0.55]

- Flag cascading parent mappings as `depending_list` proposals, carrying over required/filter/scoping hints from the child field.
- Populate `redmine_parent_custom_field_id` on cascading children and persist parent scopes (projects, trackers, roles) alongside generated parent proposals.
- Bump the custom field migration script version to `0.0.55` and align the README references.

## [0.0.52]

- Persist cascading parent/child dependencies in `migration_mapping_custom_fields.proposed_value_dependencies` so transform and push
  phases can reuse the Redmine-ready map instead of re-parsing Jira payloads.
- Default cascading custom field proposals to `depending_list`, populate parent options in `proposed_possible_values`, and surface
  parent/child scope metadata alongside the child mapping.
- Bump the custom field migration script version to `0.0.34` and extend the schema with the dependency column for depending-list
  automation.
 
## [0.0.51]

- Add first-class cascading custom field handling: detect Jira cascading selects during transform, retain parent/child identifiers, and
  build a child-to-parent lookup so dependent Redmine lists can be populated consistently via the plugin.
- Populate Redmine parent and child custom field values during issue transforms by mapping Jira child IDs back to their parent labels,
  ensuring depending-list payloads are ready for creation or extended API calls.
- Bump the custom field migration script version to `0.0.33` and the issues migration script version to `0.0.29`, updating the
  README to match the new cascading workflow guidance.

## [0.0.50]

- Decode Jira app custom object option payloads that embed JSON strings and
  extract their label lists so `jira_allowed_values` and downstream proposals
  reflect the actual label names.
- Allow app-sourced Jira custom fields to progress through automated mapping
  when metadata is available instead of forcing manual intervention.
- Refactor object values extraction from issues to eliminate NULL and combined values.
- Bump the custom field migration script version to `0.0.31`.

## [0.0.49]

- Allow object-schema Jira fields to derive list proposals from create-metadata allowed values, enabling automation instead of forced ignores.
- Bump the custom field migration script version to `0.0.30`.

## [0.0.48]

- Concatenate Jira option lists from projects/issue types with divergent `allowedValues`
  into `proposed_default_value` so Redmine creations always see the union of values
  (parents/children flattened for cascading selects) instead of a `NULL` placeholder.
- Bump the custom field migration script version to `0.0.29`.

## [0.0.47]

- Derive `proposed_is_required`, `proposed_is_filter`, `proposed_is_multiple`, and
  `proposed_default_value` from Jira create-metadata payloads so
  `migration_mapping_custom_fields` records surface concrete proposals instead of
  `NULL` placeholders.
- Interpret Jira schema types to determine multiplicity (arrays/objects vs.
  single-value option-like types) in addition to the existing schema.custom
  hints.
- Surface the new derivations in the README and bump the custom field migration
  script version to `0.0.28`.

## [0.0.46]

- Automatically mark Jira custom fields with the `object` schema type as ignored
  during the transform phase when automation is allowed, keeping existing notes
  and hashes intact so mappings with populated project/issue scopes no longer
  linger with empty Redmine proposals.
- Bump the custom field migration script version to `0.0.26` and align the
  README references.
- Bumped the custom fields migration script version to reflect the new issue-driven enrichment logic.
- Added a post-create-metadata backfill step that enriches project/issue-type field assignments with data derived from staged Jira issues.
- Implemented helpers to harvest missing field assignments and allowed values from staged issues, including metadata loading and normalization of observed values for storage

## [0.0.45]

- Automatically mark custom field mappings as ignored when Jira project or issue type scopes are empty
- Treat `migration_mapping_trackers` and `migration_mapping_projects` as the
  primary Redmine sources during the tracker transform so newly created
  trackers and projects remain matched without waiting for a fresh
  `staging_redmine_*` snapshot. The lookup now overlays mapping-table metadata
  on top of any available Redmine snapshots.
- Seed the project tracker snapshot from `migration_mapping_projects`, only
  using `staging_redmine_projects` payloads to enrich the tracker list so push
  operations still know which associations already exist.
- Bump `07_migrate_trackers.php` to version `0.0.18` and update the README
  references.

## [0.0.44]

- Normalise and persist Jira `allowedValues` for every project/issue-type
  assignment, including cascading selects, by reusing the raw field payload when
  Atlassian omits the flattened helper column so `allowed_values_json` never
  stays empty.
- Backfill missing `allowed_values_json` values in
  `staging_jira_project_issue_type_fields` during the transform phase and log
  the number of repaired rows so we can demonstrate the script actually filled
  the gaps called out in the TODO list.
- Ensure `migration_mapping_custom_fields` always receives the aggregated Jira
  project IDs, issue type IDs, and allowed-value descriptors so downstream Redmine
  proposals have the context they need to set `proposed_project_ids`,
  `proposed_tracker_ids`, `proposed_possible_values`, `proposed_role_ids`, and
  `proposed_is_required`.

## [0.0.43]

- Persist `allowed_values_json` for every Jira field that exposes
  `allowedValues`, including system types such as project, issue type,
  priority, and user pickers, so `migration_mapping_custom_fields` finally
  receives the project/issue scopes and option payloads it needs to populate
  `jira_project_ids`, `jira_issue_type_ids`, and `jira_allowed_values`.
- Capture the Jira project display name when recording
  `staging_jira_project_issue_type_fields` entries so reviewers can see the key
  and friendly name side-by-side while auditing mappings.
- Bump the custom field migration script version to `0.0.24` and align the
  README to reflect the new capability.

## [0.0.42]

- Ensure the Jira create-metadata extractor always requests expanded field
  definitions so project/issue-type rows capture allowed values, even for
  system fields, and store those option payloads in the staging table. This
  keeps `migration_mapping_custom_fields` populated with Jira project/issue
  scopes and allowed values so the transform phase can propose matching
  Redmine project/tracker links. Bump the custom field migration script to
  version `0.0.23` and align the README reference.

## [0.0.41]

- Remove the unused Jira field context extraction tables and logic in favour of
  the project/issue-type create metadata flow, trimming the schema accordingly.
- Drop the legacy `context_scope_*` and `jira_context_ids` columns from
  `migration_mapping_custom_fields` now that contexts are no longer staged, and
  keep the mapping rows scoped via the aggregated project/issue usage plus
  allowed values.
- Drop the unused Jira searcher key columns from the staging and mapping tables.
- Add CLI logging that summarises how many custom fields are mapped, how many
  are list-like or cascading, and how many carry allowed values.
- Bump the custom field migration script version to `0.0.22` and align the
  README with the simplified extraction scope.

## [0.0.37]

- Capture Jira field schema hints and normalised allowed values in `staging_jira_project_issue_type_fields`, including
  `schema_type`, `schema_custom`, and `allowed_values_json`, so the transform phase can reason about select-like fields
  without rehydrating raw payloads.
- Classify Jira fields as `system`, `jira_custom`, or `app_custom` in both `staging_jira_fields` and
  `staging_jira_project_issue_type_fields`, filtering `app_custom` entries out of the custom-field mapping pipeline and
  improving downstream Redmine decisions.
- Harden custom field detection to avoid PHP version quirks, bump the custom field migration script version to `0.0.18`, and
  align the README references.

## [0.0.36]

- Switch the Jira create-metadata extraction to the scoped endpoints (`/issue/createmeta/{project}/issuetypes[/ {id}]`) so
  `staging_jira_project_issue_type_fields` reliably captures per-project issue type fields on Jira Cloud.
- Add logging around project/issue-type discovery for easier debugging when create permissions or API responses are empty.
- Bump the custom field migration script version to `0.0.17` and refresh the README references.

## [0.0.35]

- Iterate over staged Jira projects and their issue types via `/rest/api/3/issue/createmeta` to capture the fields exposed on
  each create screen in `staging_jira_project_issue_type_fields`, including required flags.
- Blend the project/issue-type visibility into context grouping so Redmine proposals include tracker and project scopes even
  when Jira contexts are global.
- Bump the custom field migration script version to `0.0.16` and refresh the README with the new extraction step.

## [0.0.34]

- Add an update path for custom field associations by extending `migration_mapping_custom_fields.migration_status` with
  `READY_FOR_UPDATE`, detecting missing project/tracker links during transform, and persisting the desired associations in the
  proposed columns.
- Collect an association update plan from staging snapshots and apply the merged project/tracker lists to existing Redmine
  custom fields (including cascading parents) through the extended API, updating automation hashes and statuses after
  successful synchronisation.
- Surface the planned association changes in manual mode when the extended API is disabled and bump the custom field migration
  script version to `0.0.14` with refreshed documentation.

## [0.0.33]

- Capture Jira issue type usage per project by expanding `/rest/api/3/project/search` and store the associations in
  `staging_jira_issue_type_projects`, defaulting missing scopes to `GLOBAL` so mappings clearly distinguish project-scoped and
  global issue types.
- Derive proposed Redmine project links from the recorded Jira usage, preserving the associations in
  `migration_mapping_trackers.proposed_redmine_project_ids` for both project-scoped and global issue types and marking matched
  trackers as `READY_FOR_UPDATE` when they are missing from the target projects.
- Allow the project-link push plan to use the recorded project lists regardless of Jira scope so existing Redmine trackers are
  added to every mapped project, and bump the tracker CLI version to `0.0.16` with refreshed README guidance.

## [0.0.32]

- Capture Jira issue type scope and project IDs by fetching detailed payloads
  when the list response omits scope metadata, ensuring
  `staging_jira_issue_types.scope_type` and `scope_project_id` are populated.
- Mark matched trackers that need new project associations as
  `READY_FOR_UPDATE` and link them during the push phase instead of treating
  them as creations. Mapped project IDs now feed both the transform and project
  update plan.
- Bump the tracker CLI version to `0.0.15`.

## [0.0.31]

- Refresh the tracker snapshot via the extended API when available so project
  links, custom fields, and other tracker attributes are captured in
  `staging_redmine_trackers`, with safe fallbacks and warnings when the plugin
  is unreachable.
- Bump the tracker CLI version to `0.0.14`.

## [0.0.30]

- Record the mapped Redmine project IDs for project-scoped trackers in
  `migration_mapping_trackers.proposed_redmine_project_ids` so they can be
  reviewed or overridden before linking.
- Use the recorded project list when planning tracker-to-project updates and
  surface warnings if mappings are missing from the database snapshot.
- Bump the tracker CLI version to `0.0.13`.

## [0.0.29]

- Link trackers to their mapped Redmine projects during the push phase, adding
  missing associations for project-scoped Jira issue types after tracker
  creation or when existing trackers already exist. The tracker CLI now reports
  version `0.0.12` and previews the planned project updates during `--dry-run`
  or confirmed runs.

## [0.0.28]

- Move attachment metadata harvesting out of the issue extractor and into the
  attachment migration script's Jira phase so staged issues can be reused
  without extra Jira API calls. The attachment CLI now reports version `0.0.17`
  and the issue CLI version is `0.0.27`.
- Refresh the README to document the new ordering (issue extract before the
  attachment Jira phase), the attachment staging behaviour, and the updated
  version numbers.

## [0.0.27]

- Extend the custom field transform so it reads the latest usage snapshot,
  auto-ignores Jira custom fields that never appear in staged issues, and
  surfaces the usage statistics in the mapping notes to aid manual reviews.
- First attempt to handle unsupported field types.
- Implement parallel attachment downloads to speed up the migration process.
- Surface an `Ignored (unused)` counter in the transform summary and bump the
  custom field CLI version to `0.0.12`.

## [0.0.26]

- Harden the custom field usage phase so it only analyses issues whose raw Jira
  payload is valid JSON, preventing the aggregation query from failing when
  staging data was ingested before the issue extraction script was fixed.

## [0.0.25]

- Fix `10_migrate_issues.php` so the Jira extraction phase calls
  `/rest/api/3/search/jql` via query parameters instead of the deprecated POST
  payload, reintroducing the `fieldsByKeys` flag and honouring Jira's reported
  batch size to prevent the `Invalid request payload` errors seen when
  requesting rendered HTML descriptions. The CLI now reports version `0.0.25`.

## [0.0.24]

- Capture Jira's rendered HTML descriptions and comment bodies in the staging
  tables, refresh the attachment metadata index, and depend on
  `league/html-to-markdown` so the issue and journal transforms can convert the
  HTML into CommonMark while rewriting Jira attachment links to Redmine's
  `attachment:` syntax.
- Document the new workflow in the README and bump the CLI versions so operators
  know the description/comment Markdown now honours the rendered HTML payloads
  and attachment metadata stored during extraction.

## [0.0.23]

- Teach `10_migrate_issues.php` to persist Jira issue link metadata in the new
  `staging_jira_issue_links` table so downstream scripts can reason about
  relation directionality, and relax `staging_jira_attachments.created_at` to
  accept `NULL` values so the association hint fallback works when Jira omits a
  timestamp. The CLI now reports version `0.0.23`.
- Introduce `12_migrate_subtasks.php` to analyse Jira parent/child pairs and
  update the corresponding Redmine `parent_issue_id` assignments with optional
  dry-run previews and automation-hash preservation.
- Add `13_migrate_issue_relations.php` plus the
  `migration_mapping_issue_relations` table to map Jira links to Redmine relation
  types, queue rows for review, and create the final relations via the Redmine
  REST API.
- Document the new workflow in the README, extend the script order table with
  the subtask/relation steps, and describe the new CLIs so operators know how to
  run them before the tags migration.

## [0.0.22]

- Roll back the Jira extractor to the `/rest/api/3/search/jql` endpoint and trim
  any user-supplied `ORDER BY` clause before the script appends its own
  deterministic `ORDER BY id ASC`, preventing the Atlassian 410 and duplicate
  sort errors during per-project pagination. The CLI now reports version
  `0.0.22`.
- Simplify the Jira search payload by sending only the `fields` array (Jira
  defaults the rest), avoiding the `Invalid request payload` responses caused by
  schema mismatches.
- Document the new version number and the automatic `ORDER BY` sanitiser in the
  README so operators know custom JQL snippets should focus on filters only.

## [0.0.21]

- Fix the Jira search payload built by `10_migrate_issues.php` so it passes an
  array for both `fields` and `expand`, plus an explicit `fieldsByKeys` flag,
  addressing the Atlassian `Invalid request payload` errors reported after the
  per-project pagination refactor. The CLI now reports version `0.0.21`.
- Update the README option table to reflect the new version number so operators
  can easily confirm they are running the patched build.

## [0.0.20]

- Rebuild `10_migrate_issues.php` so the Jira extraction phase iterates over
  every project recorded in `migration_mapping_projects`, querying
  `/rest/api/3/search` with `project = "KEY"` filters, keyset pagination, and an
  array-based `fields` payload before marking the new
  `issues_extracted_at` timestamp once a project finishes. The CLI now reports
  version `0.0.20`, and reruns automatically resume with the next incomplete
  project instead of relying on manual JQL scoping.
- Extend the schema with `migration_mapping_projects.issues_extracted_at`,
  document the resumable workflow (and upgrade SQL) in the README, and clarify
  that `migration.issues.jql` is AND-ed with the per-project constraint in
  `config/config.default.php`.

## [0.0.19]

- Fix `10_migrate_issues.php` so Jira extraction once again targets
  `/rest/api/3/search/jql`, sending the request payload Atlassian now
  expects (`fields` as an array) while preserving the `ORDER BY id ASC`
  keyset pagination strategy.
- Inject a default `id >= 0` constraint when no custom JQL is supplied so
  the initial search call always satisfies Jira's bounded query
  requirement, and bump the CLI version plus README references to
  `0.0.19`.

## [0.0.18]

- Rework `10_migrate_issues.php` so Jira extraction uses keyset pagination
  against `/rest/api/3/search`, automatically enforcing `ORDER BY id ASC` and
  appending `id > <last_seen_id>` filters to stream every issue without
  requiring per-project scoping. The CLI version now reports `0.0.18`.
- Drop the short-lived `migration.issues.project_keys` knob from the default
  configuration, document the new pagination behaviour in the README, and keep
  `migration.issues.jql` as the optional place to add custom filters when
  batching exports.

## [0.0.17]

- Enforce bounded Jira searches during issue extraction by teaching
  `10_migrate_issues.php` to require `migration.issues.project_keys` (or a fully
  scoped custom JQL) before calling `/rest/api/3/search/jql`, automatically
  constructing `project in (...) ORDER BY created ASC` queries for bulk project
  migrations.
- Extend `config/config.default.php` and the README with the new
  `migration.issues.project_keys` knob plus guidance on batching projects per
  run so large catalogues can be migrated safely.
- Bump the issue migration CLI version to `0.0.17` and update the documented
  version hints.

## [0.0.16]

- Update `10_migrate_issues.php` to call Jira's `/rest/api/3/search/jql` endpoint
  with next-page tokens so the attachments, issues, and journals pipelines no
  longer hit the retired search API.
- Audit the other CLI scripts to ensure no deprecated Jira search endpoints are
  referenced.

## [0.0.15]

- Split `09_migrate_attachments.php` into explicit `jira`, `pull`, `transform`,
  and `push` phases with independent confirmations, optional per-phase limits,
  and refreshed CLI help text.
- Allow operators to stage smaller batches by toggling the new
  `download_enabled` / `upload_enabled` flags on `migration_mapping_attachments`
  rows and storing attachment file sizes as `jira_filesize` for reporting.
- Update the README and database schema to document the new columns, workflow,
  and testing guidance, and bump the attachment script version to `0.0.15`.

## [0.0.14]

- Introduce `09_migrate_attachments.php` to synchronise attachment mappings,
  download binaries into the working directory, and pre-upload them to Redmine
  while labelling each file for issue or journal association.
- Update `10_migrate_issues.php` to consume pre-generated attachment tokens,
  classify association hints, and reconcile Redmine attachment identifiers after
  issue creation.
- Add `11_migrate_journals.php` to migrate Jira comments and changelog entries,
  converting ADF payloads to notes and reusing journal-scoped attachment tokens.
- Extend the schema with attachment association metadata (`redmine_issue_id`,
  `association_hint`), refresh the README with the new workflows, and bump script
  versions to `0.0.14`.

## [0.0.13]

- Teach `10_migrate_issues.php` to download Jira attachments into
  `tmp/attachments/jira`, maintain the `migration_mapping_attachments` workflow,
  and upload binaries to Redmine ahead of issue creation while capturing upload
  tokens for later association.
- Extend the push phase reporting so operators can see pending attachment
  counts during dry runs and successful uploads when `--confirm-push` is used.
- Refresh the README with the new attachment pipeline details, the updated
  script version, and guidance on ensuring the temporary directory is writable.

## [0.0.12]

- Streamline the issue migration by dropping the Redmine issue snapshot phase
  and table, keeping the staging schema focused on the Jira catalogue while the
  mapping table records successful Redmine creations.
- Update `10_migrate_issues.php` to run only the Jira, transform, and push
  phases, bump its CLI version, and document the leaner workflow in the README.

## [0.0.11]

- Add `10_migrate_issues.php`, completing the issue ETL workflow with Jira
  extraction (including attachment and label snapshots), a Redmine issue
  snapshot, dependency-aware transforms, and an optional push phase that posts
  to `POST /issues.json` after explicit confirmation.
- Extend the staging schema with richer issue metadata, add the
  `staging_redmine_issues` table, and expand `migration_mapping_issues` so the
  transform phase can propose Redmine project/tracker/status/priority/user
  identifiers while preserving manual overrides via automation hashes.
- Introduce a configurable `migration.issues` section (Jira batch size and
  fallback Redmine IDs) and capture Jira attachments/labels alongside issues so
  downstream steps (custom field usage, attachments, tags) operate on fresh
  staging data.
- Refresh the README with the new issue migration workflow and update all CLI
  entry points to version `0.0.11`.

## [0.0.10]

- Extend `08_migrate_custom_fields.php` with native support for cascading select
  fields by detecting the `redmine_depending_custom_fields` plugin, creating the
  required parent list via the extended API, and issuing dependent field
  requests against the plugin's REST endpoint.
- Capture Jira context option hierarchies (parent/child relationships) in
  `staging_jira_field_contexts` and propagate them through the transform so
  migrations can surface parent sets, child unions, and dependency maps.
- Persist Redmine parent custom field identifiers in the mapping table, so
  repeated runs and manual acknowledgements honour previously created parent
  fields.
- Refresh the README with the new cascading workflow, document the plugin
  behaviour, and bump all CLI entry points to `0.0.10`.

## [0.0.9]

- Extend `08_migrate_custom_fields.php` to retrieve Jira field contexts and allowed option values, persisting them
  in the new `staging_jira_field_contexts` table for downstream analysis.
- Expand `migration_mapping_custom_fields` with context metadata (hashes, Jira project/issue type lists, allowed
  values) so the transform phase can reason about per-project option differences.
- Update the transform logic to split divergent Jira contexts into distinct Redmine proposals with context-aware
  names, automatically populate option lists, and map Jira projects/issue types to Redmine projects and trackers.
- Refresh the README with the context-aware workflow, clarify the extended API checklist, and bump all CLI entry
  points to version `0.0.9`.

## [0.0.8]

- Add `08_migrate_custom_fields.php`, covering the full extract/transform/push pipeline for Jira custom fields with
  optional automation via the `redmine_extended_api` plugin.
- Enrich the staging and mapping schema for custom fields with metadata columns (schema hints, proposed Redmine
  attributes, automation hashes) so manual overrides persist across reruns and list-style fields can capture
  proposed option values.
- Teach the README about the new script, update the extended API guidance with custom field examples, and bump all
  CLI entry points to `0.0.8`.

## [0.0.7]

- Introduce `07_migrate_trackers.php` to reconcile Jira issue types with Redmine trackers, including optional
  creation support through the `redmine_extended_api` plugin.
- Extend the tracker mapping table with Jira metadata, proposed Redmine attributes, and automation hashes so manual
  overrides persist across reruns, and teach the transform phase to derive default status IDs automatically.
- Add `migration.trackers.default_redmine_status_id` to the configuration and document the new workflow in the README.
- Refresh the README examples to cover the tracker automation flow and bump all CLI entry points to `0.0.7`.

## [0.0.6]

- Add `05_migrate_statuses.php` to extract Jira statuses, refresh the Redmine snapshot, reconcile mappings with
  automation-hash preservation, and print a manual creation checklist.
- Add `06_migrate_priorities.php` following the same phased workflow for issue priorities, including Jira/Redmine
  upserts, reconciliation, and a manual push summary.
- Extend the staging schema with richer metadata for status and priority mappings (names, proposed values, and
  automation hashes) so manual overrides persist across reruns.
- Bump all CLI entry points to `0.0.6` and document the new scripts and workflow guidance in the README.

## [0.0.5]

- Introduce `04_migrate_roles.php` to reconcile Jira project roles with Redmine projects, groups, and roles, including a manual
  push checklist for assigning groups to projects.
- Extend the staging schema with `staging_jira_project_role_actors`, enrich `migration_mapping_roles`, and add `migration_mapping_project_role_groups` to track per-project role memberships.
- Add `migration.roles.default_redmine_role_id` configuration support and expand the README with workflow guidance for the new script.
- Bump all CLI entry points to `0.0.5` and refresh the documentation to reflect the updated version and role-mapping capabilities.
- Capture Redmine group project role memberships in staging and automatically mark existing assignments as already recorded during the role transform.

## [0.0.4]

- Add `01_migrate_projects.php`, covering the full extract/transform/push workflow for Redmine projects (with dry-run previews and creation support).
- Promote project synchronisation to the first migration step and renumber the user and group scripts to `02_migrate_users.php` and `03_migrate_groups.php` respectively.
- Bump the CLI script versions to `0.0.4` and refresh the README to document the new entry point, updated command names, and workflow ordering.

## [0.0.3]

- Extend `02_migrate_groups.php` to ingest Jira and Redmine group memberships, persisting them in the new `staging_*_group_members`
  tables and the `migration_mapping_group_members` state machine.
- Rework the transform phase so it reconciles both group metadata and memberships, introducing `AWAITING_GROUP`, `AWAITING_USER`,
  and `READY_FOR_ASSIGNMENT` statuses while continuing to respect automation hashes.
- Teach the push phase to assign missing Redmine users to existing or newly created groups (with dry-run previews) before
  updating the mapping tables.
- Streamline the membership staging tables to store only identifiers and raw payloads, deriving names from the user/group
  snapshots while keeping the Jira group name in the membership mapping for operator context.
- Update the staging schema, README, and CLI documentation to reflect the membership workflow and the new recommended
  migration order where project synchronisation (`00_sync_projects.php`) leads the pipeline.
- Ensure the membership transform re-evaluates groups once a Redmine identifier is present so rows no longer stall in
  `AWAITING_GROUP` after successful creations.
- Reset membership automation hashes when group identifiers are backfilled from the mapping table, so subsequent transforms
  treat them as automation-managed rows instead of reporting spurious manual overrides.
- Allow `01_migrate_users.php` to read `migration.users.auth_source_id` so newly created Redmine accounts can be bound to the correct authentication mode (e.g. LDAP).
- Include the optional `auth_source_id` in push requests when configured and document the new toggle with refreshed sample configuration.

## [0.0.2]

- Implement the transform/reconciliation phase of `users.php`.
- Extend the `migration_mapping_users` table with Jira metadata, proposed Redmine values, and a `match_type` column.
- Match Jira accounts to Redmine users by login/e-mail and derive default first/last names from Jira `display_name`.
- Document the new workflow, CLI options, and manual override process.
- Preserve manual overrides in `migration_mapping_users` with automation hashes, reporting skipped rows in the CLI summary.
- Treat blank or malformed `automation_hash` values as cleared so rerunning the transform re-analyses those rows instead of
  flagging false manual overrides.
- Add a `proposed_redmine_status` column defaulting to `LOCKED` so operators can choose whether new Redmine accounts start active or locked, and document the rerun behaviour.
- Respect the configurable `migration.users.default_redmine_user_status` toggle when preparing proposed Redmine accounts.
- Stop expanding Redmine group and membership data during the user snapshot to keep the import leaner for now.
- Implement the push phase for `users`

## [0.0.1]

- Initial commit
- Add composer dependencies
- Add a first migration schema
- Add a README.md
- Add a configuration logic
- Retrieve users from Jira
- Retrieve users from Redmine
- Add CLI controls, docs, and changelog for the user migration script
