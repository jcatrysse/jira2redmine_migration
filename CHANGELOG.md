# Changelog

All notable changes to this project will be documented in this file.

## [TODO]

- Migrate custom fields script: link custom fields to projects and trackers in Redmine
    - table migration_mapping_custom_fields: jira_project_ids, jira_issue_type_ids, jira_allowed_values, ... stay empty
    - table migration_mapping_custom_fields: proposed_possible_values, proposed_tracker_ids, proposed_project_ids, proposed_role_ids, proposed_is_required, ... stay empty
    - table staging_jira_project_issue_type_fields: allowed_values_json, ... needs to be validated
    - Examples: 
        - Error: [manual] Jira custom field Survey Data Type: List-style Jira field requires allowed option values; Jira metadata exposes no allowedValues payload. Usage snapshot (2025-11-19 10:12:48): non-empty values in 48/3230 issues (values present in 48/3230).
        - Error: [manual] Jira custom field Survey Hardware: Unable to parse cascading Jira custom field options for dependent field creation. Usage snapshot (2025-11-19 10:12:48): non-empty values in 793/3230 issues (values present in 793/3230). Requires the redmine_depending_custom_fields plugin to migrate cascading selects.
    - Example payloads for schema_type en raw_field:
      - INSERT INTO `staging_jira_project_issue_type_fields` VALUES('array', '{\"key\": \"versions\", \"name\": \"Betreft versies\", \"schema\": {\"type\": \"array\", \"items\": \"version\", \"system\": \"versions\"}, \"fieldId\": \"versions\", \"required\": false, \"operations\": [\"set\", \"add\", \"remove\"], \"allowedValues\": [], \"hasDefaultValue\": false}');
      - INSERT INTO `staging_jira_project_issue_type_fields` VALUES('option', '{\"key\": \"customfield_10358\", \"name\": \"Vehicle type\", \"schema\": {\"type\": \"option\", \"custom\": \"com.atlassian.jira.plugin.system.customfieldtypes:select\", \"customId\": 10358}, \"fieldId\": \"customfield_10358\", \"required\": true, \"operations\": [\"set\"], \"allowedValues\": [{\"id\": \"11352\", \"self\": \"https://geoxyz.atlassian.net/rest/api/3/customFieldOption/11352\", \"value\": \"AUV\"}, {\"id\": \"11353\", \"self\": \"https://geoxyz.atlassian.net/rest/api/3/customFieldOption/11353\", \"value\": \"VROV\"}, {\"id\": \"11354\", \"self\": \"https://geoxyz.atlassian.net/rest/api/3/customFieldOption/11354\", \"value\": \"WC-ROV\"}], \"hasDefaultValue\": false}');
      - INSERT INTO `staging_jira_project_issue_type_fields` VALUES('string', '{\"key\": \"summary\", \"name\": \"Samenvatting\", \"schema\": {\"type\": \"string\", \"system\": \"summary\"}, \"fieldId\": \"summary\", \"required\": true, \"operations\": [\"set\"], \"hasDefaultValue\": false}');
      - INSERT INTO `staging_jira_project_issue_type_fields` VALUES('object', '{\"key\": \"label-manager-for-jira__f2b135ef-0fa6-4d1a-a418-056675b4981f\", \"name\": \"Offshore Vessel\", \"schema\": {\"type\": \"object\", \"custom\": \"ari:cloud:ecosystem::extension/639553fa-ee05-4cc8-98f1-cf5b46518814/37f393d5-e134-4d8a-831b-be8f4f66c882/static/label-manager-for-jira\", \"customId\": 10033, \"configuration\": {\"readOnly\": false, \"environment\": \"PRODUCTION\", \"customRenderer\": true}}, \"fieldId\": \"customfield_10033\", \"required\": false, \"operations\": [\"add\", \"set\", \"edit\", \"remove\"], \"hasDefaultValue\": false}');
      - INSERT INTO `staging_jira_project_issue_type_fields` VALUES('issuetype', '{\"key\": \"issuetype\", \"name\": \"Issuetype\", \"schema\": {\"type\": \"issuetype\", \"system\": \"issuetype\"}, \"fieldId\": \"issuetype\", \"required\": true, \"operations\": [], \"allowedValues\": [{\"id\": \"10133\", \"name\": \"Milestone\", \"self\": \"https://geoxyz.atlassian.net/rest/api/3/issuetype/10133\", \"iconUrl\": \"https://geoxyz.atlassian.net/rest/api/2/universal_avatar/view/type/issuetype/avatar/10308?size=medium\", \"subtask\": false, \"avatarId\": 10308, \"description\": \"\", \"hierarchyLevel\": 0}], \"hasDefaultValue\": false}');
      - INSERT INTO `staging_jira_project_issue_type_fields` VALUES('issuelink', '{\"key\": \"parent\", \"name\": \"Bovenliggende\", \"schema\": {\"type\": \"issuelink\", \"system\": \"parent\"}, \"fieldId\": \"parent\", \"required\": true, \"operations\": [\"set\"], \"hasDefaultValue\": false}');
      - INSERT INTO `staging_jira_project_issue_type_fields` VALUES('priority', '{\"key\": \"priority\", \"name\": \"Prioriteit\", \"schema\": {\"type\": \"priority\", \"system\": \"priority\"}, \"fieldId\": \"priority\", \"required\": false, \"operations\": [\"set\"], \"defaultValue\": {\"id\": \"3\", \"name\": \"Medium\", \"self\": \"https://geoxyz.atlassian.net/rest/api/3/priority/3\", \"iconUrl\": \"https://geoxyz.atlassian.net/images/icons/priorities/medium_new.svg\"}, \"allowedValues\": [{\"id\": \"10000\", \"name\": \"Showstopper\", \"self\": \"https://geoxyz.atlassian.net/rest/api/3/priority/10000\", \"iconUrl\": \"https://geoxyz.atlassian.net/rest/api/3/universal_avatar/view/type/priority/avatar/10558?size=medium\", \"avatarId\": 10558}, {\"id\": \"2\", \"name\": \"High\", \"self\": \"https://geoxyz.atlassian.net/rest/api/3/priority/2\", \"iconUrl\": \"https://geoxyz.atlassian.net/images/icons/priorities/high_new.svg\"}, {\"id\": \"3\", \"name\": \"Medium\", \"self\": \"https://geoxyz.atlassian.net/rest/api/3/priority/3\", \"iconUrl\": \"https://geoxyz.atlassian.net/images/icons/priorities/medium_new.svg\"}, {\"id\": \"4\", \"name\": \"Low\", \"self\": \"https://geoxyz.atlassian.net/rest/api/3/priority/4\", \"iconUrl\": \"https://geoxyz.atlassian.net/images/icons/priorities/low_new.svg\"}], \"hasDefaultValue\": true}');
      - INSERT INTO `staging_jira_project_issue_type_fields` VALUES('project', '{\"key\": \"project\", \"name\": \"Project\", \"schema\": {\"type\": \"project\", \"system\": \"project\"}, \"fieldId\": \"project\", \"required\": true, \"operations\": [\"set\"], \"allowedValues\": [{\"id\": \"10210\", \"key\": \"GSPP\", \"name\": \"GeoS Project Planning\", \"self\": \"https://geoxyz.atlassian.net/rest/api/3/project/10210\", \"avatarUrls\": {\"16x16\": \"https://geoxyz.atlassian.net/rest/api/3/universal_avatar/view/type/project/avatar/10406?size=xsmall\", \"24x24\": \"https://geoxyz.atlassian.net/rest/api/3/universal_avatar/view/type/project/avatar/10406?size=small\", \"32x32\": \"https://geoxyz.atlassian.net/rest/api/3/universal_avatar/view/type/project/avatar/10406?size=medium\", \"48x48\": \"https://geoxyz.atlassian.net/rest/api/3/universal_avatar/view/type/project/avatar/10406\"}, \"simplified\": false, \"projectTypeKey\": \"software\"}], \"hasDefaultValue\": false}');
      - INSERT INTO `staging_jira_project_issue_type_fields` VALUES('user', '{\"key\": \"reporter\", \"name\": \"Melder\", \"schema\": {\"type\": \"user\", \"system\": \"reporter\"}, \"fieldId\": \"reporter\", \"required\": true, \"operations\": [\"set\"], \"autoCompleteUrl\": \"https://geoxyz.atlassian.net/rest/api/3/user/recommend?context=Reporter&issueKey=\", \"hasDefaultValue\": true}');
      - INSERT INTO `staging_jira_project_issue_type_fields` VALUES('option-with-child', '{\"key\": \"customfield_10129\", \"name\": \"SeaSoils Hardware\", \"schema\": {\"type\": \"option-with-child\", \"custom\": \"com.atlassian.jira.plugin.system.customfieldtypes:cascadingselect\", \"customId\": 10129}, \"fieldId\": \"customfield_10129\", \"required\": false, \"operations\": [\"set\"], \"allowedValues\": [{\"id\": \"10925\", \"self\": \"https://geoxyz.atlassian.net/rest/api/3/customFieldOption/10925\", \"value\": \"CPT Container\"}, {\"id\": \"10923\", \"self\": \"https://geoxyz.atlassian.net/rest/api/3/customFieldOption/10923\", \"value\": \"CPT Unit\", \"children\": [{\"id\": \"10930\", \"self\": \"https://geoxyz.atlassian.net/rest/api/3/customFieldOption/10930\", \"value\": \"Manta 200\"}]}, {\"id\": \"10924\", \"self\": \"https://geoxyz.atlassian.net/rest/api/3/customFieldOption/10924\", \"value\": \"CPT Winch\", \"children\": [{\"id\": \"10931\", \"self\": \"https://geoxyz.atlassian.net/rest/api/3/customFieldOption/10931\", \"value\": \"Umbilical\"}, {\"id\": \"10932\", \"self\": \"https://geoxyz.atlassian.net/rest/api/3/customFieldOption/10932\", \"value\": \"Rod Wire\"}]}, {\"id\": \"10990\", \"self\": \"https://geoxyz.atlassian.net/rest/api/3/customFieldOption/10990\", \"value\": \"Day Grab\", \"children\": [{\"id\": \"10991\", \"self\": \"https://geoxyz.atlassian.net/rest/api/3/customFieldOption/10991\", \"value\": \"2.5L\"}, {\"id\": \"10992\", \"self\": \"https://geoxyz.atlassian.net/rest/api/3/customFieldOption/10992\", \"value\": \"5L\"}]}, {\"id\": \"10929\", \"self\": \"https://geoxyz.atlassian.net/rest/api/3/customFieldOption/10929\", \"value\": \"Offshore Lab Container 20ft\", \"children\": [{\"id\": \"10935\", \"self\": \"https://geoxyz.atlassian.net/rest/api/3/customFieldOption/10935\", \"value\": \"Lab/Office\"}, {\"id\": \"10936\", \"self\": \"https://geoxyz.atlassian.net/rest/api/3/customFieldOption/10936\", \"value\": \"Lab/Sample Storage\"}]}, {\"id\": \"10989\", \"self\": \"https://geoxyz.atlassian.net/rest/api/3/customFieldOption/10989\", \"value\": \"Piston Corer\"}, {\"id\": \"10928\", \"self\": \"https://geoxyz.atlassian.net/rest/api/3/customFieldOption/10928\", \"value\": \"VC Container\"}, {\"id\": \"10926\", \"self\": \"https://geoxyz.atlassian.net/rest/api/3/customFieldOption/10926\", \"value\": \"VC Unit\", \"children\": [{\"id\": \"10933\", \"self\": \"https://geoxyz.atlassian.net/rest/api/3/customFieldOption/10933\", \"value\": \"Geo Corer 6000\"}]}, {\"id\": \"10927\", \"self\": \"https://geoxyz.atlassian.net/rest/api/3/customFieldOption/10927\", \"value\": \"VC Winch\", \"children\": [{\"id\": \"10934\", \"self\": \"https://geoxyz.atlassian.net/rest/api/3/customFieldOption/10934\", \"value\": \"Umbilical\"}]}], \"hasDefaultValue\": false}');
      - INSERT INTO `staging_jira_project_issue_type_fields` VALUES('date', '{\"key\": \"duedate\", \"name\": \"Einddatum\", \"schema\": {\"type\": \"date\", \"system\": \"duedate\"}, \"fieldId\": \"duedate\", \"required\": false, \"operations\": [\"set\"], \"hasDefaultValue\": false}');
      - INSERT INTO `staging_jira_project_issue_type_fields` VALUES('timetracking', '{\"key\": \"timetracking\", \"name\": \"Tijdregistratie\", \"schema\": {\"type\": \"timetracking\", \"system\": \"timetracking\"}, \"fieldId\": \"timetracking\", \"required\": false, \"operations\": [\"set\", \"edit\"], \"hasDefaultValue\": false}');
      - INSERT INTO `staging_jira_project_issue_type_fields` VALUES('team', '{\"key\": \"customfield_10001\", \"name\": \"Team\", \"schema\": {\"type\": \"team\", \"custom\": \"com.atlassian.jira.plugin.system.customfieldtypes:atlassian-team\", \"customId\": 10001, \"configuration\": {\"com.atlassian.jira.plugin.system.customfieldtypes:atlassian-team\": true}}, \"fieldId\": \"customfield_10001\", \"required\": false, \"operations\": [\"set\"], \"autoCompleteUrl\": \"https://geoxyz.atlassian.net/gateway/api/v1/recommendations\", \"hasDefaultValue\": false}');

- Verify if all automation hashes align with the latest database schemas.
- Migrate custom fields script: retrieve object information, or create them manually as enumeration, check if issue contains readable values.
- Migrate custom fields script: add support for datetime fields in redmine using https://github.com/jcatrysse/redmine_datetime_custom_field and the https://github.com/jcatrysse/redmine_extended_api for API access.
- Migrate custom fields script: investigate unsupported field types (any, team, option, option-with-child, option2, sd-customerrequesttype, object, sd-approvals, ...)
- Migrate custom fields script: investigate cascading fields (option-with-child) and how to match them with https://github.com/jcatrysse/redmine_depending_custom_fields
- Migrate custom fields script: investigate and validate the transformation from Jira Context to separate custom fields in Redmine.
- Migrate custom fields script: in table migration_mapping_custom_fields, when jira_project_ids or jira_issue_type_ids is empty, set migration_status to IGNORED.
- Migrate trackers script: in the transform phase, the system seems to match the redmine projects or other information with the staging_redmine tables, while the relevant information about redmine values should come from the mapping tables, they are up to date after a push. 
- Migrate trackers script: in the transform phase, jira_scope_project_id should be set with the jira project id's, same logic as for proposed_redmine_project_ids, but with the Jira is's.
- Migrate trackers script: in the transform phase, trackers who have no proposed_redmine_project_ids should be set to IGNORED.
- Migrate trackers script: seems to call the Redmine projects endpoint to link the tracker to the projects, while the tracker should be linked to the projects in the extended_api tracker endpoint, or use the project endpoint if the extended_api is not available.
  - Payload on the standard Redmine API for projects: {
    "project":{
    "name":"Example name",
    "identifier":"example_name",
    "description":"Description of exapmple project",
    "is_public":false,
    "parent_id":1,
    "inherit_members":false,
    "tracker_ids":[
    1,
    2,
    3,
    4,
    5
    ],
    "enabled_module_names":[
    "issue_tracking"
    ],
    "custom_field_values":{
    "1":"VALUE"
    }
    }
    }
- Fine-tune the attachments, issues, and journals scripts.
- Migrate issues script: on a rerun, newer issues should be fetched.
- Migrate issues script: on transform, the script should ignore custom fields we didn't create in Redmine, based on the migration_mapping_custom_fields table.
- Create the missing scripts: labels, (document) categories, milestones, watchers, ...
- Validate we can push authors and creation timestamps to Redmine.
- Check if cascading fields are one value, or a value for every member of the family.
- Prefer moving to Redmine Enumerations and not lists, watchout to map the ID's of the enumerations for easy mapping between issue values.

## [0.0.46] - 2025-12-15

- Pass the mapped Redmine project IDs to the extended API tracker payloads so
  freshly created trackers are associated with their projects immediately.
- Revert the project-tracker snapshot back to `staging_redmine_projects` so the
  push phase only updates associations that exist in the latest Redmine
  snapshot, preventing erroneous updates against missing project endpoints.
- Bump `07_migrate_trackers.php` to version `0.0.19` and refresh the README
  references.

## [0.0.45] - 2025-12-14

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

## [0.0.43] - 2025-12-12

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

## [0.0.42] - 2025-12-11

- Ensure the Jira create-metadata extractor always requests expanded field
  definitions so project/issue-type rows capture allowed values, even for
  system fields, and store those option payloads in the staging table. This
  keeps `migration_mapping_custom_fields` populated with Jira project/issue
  scopes and allowed values so the transform phase can propose matching
  Redmine project/tracker links. Bump the custom field migration script to
  version `0.0.23` and align the README reference.

## [0.0.41] - 2025-12-10

- Remove the unused Jira field context extraction tables and logic in favour of
  the project/issue-type create metadata flow, trimming the schema accordingly.
- Drop the unused Jira searcher key columns from the staging and mapping tables.
- Add CLI logging that summarises how many custom fields are mapped, how many
  are list-like or cascading, and how many carry allowed values.
- Bump the custom field migration script version to `0.0.21` and align the
  README with the simplified extraction scope.

## [0.0.41] - 2025-12-10

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

## [0.0.35] - 2025-12-07

- Iterate over staged Jira projects and their issue types via `/rest/api/3/issue/createmeta` to capture the fields exposed on
  each create screen in `staging_jira_project_issue_type_fields`, including required flags.
- Blend the project/issue-type visibility into context grouping so Redmine proposals include tracker and project scopes even
  when Jira contexts are global.
- Bump the custom field migration script version to `0.0.16` and refresh the README with the new extraction step.

## [0.0.35] - 2025-12-07

- Iterate over staged Jira projects and their issue types via `/rest/api/3/issue/createmeta` to capture the fields exposed on
  each create screen in `staging_jira_project_issue_type_fields`, including required flags.
- Blend the project/issue-type visibility into context grouping so Redmine proposals include tracker and project scopes even
  when Jira contexts are global.
- Bump the custom field migration script version to `0.0.16` and refresh the README with the new extraction step.

## [0.0.36] - 2025-12-08

- Switch the Jira create-metadata extraction to the scoped endpoints (`/issue/createmeta/{project}/issuetypes[/ {id}]`) so
  `staging_jira_project_issue_type_fields` reliably captures per-project issue type fields on Jira Cloud.
- Add logging around project/issue-type discovery for easier debugging when create permissions or API responses are empty.
- Bump the custom field migration script version to `0.0.17` and refresh the README references.

## [0.0.35] - 2025-12-07

- Iterate over staged Jira projects and their issue types via `/rest/api/3/issue/createmeta` to capture the fields exposed on
  each create screen in `staging_jira_project_issue_type_fields`, including required flags.
- Blend the project/issue-type visibility into context grouping so Redmine proposals include tracker and project scopes even
  when Jira contexts are global.
- Bump the custom field migration script version to `0.0.16` and refresh the README with the new extraction step.

## [0.0.36] - 2025-12-08

- Switch the Jira create-metadata extraction to the scoped endpoints (`/issue/createmeta/{project}/issuetypes[/ {id}]`) so
  `staging_jira_project_issue_type_fields` reliably captures per-project issue type fields on Jira Cloud.
- Add logging around project/issue-type discovery for easier debugging when create permissions or API responses are empty.
- Bump the custom field migration script version to `0.0.17` and refresh the README references.

## [0.0.37] - 2025-12-09

- Capture Jira field schema hints and normalised allowed values in `staging_jira_project_issue_type_fields`, including
  `schema_type`, `schema_custom`, and `allowed_values_json`, so the transform phase can reason about select-like fields
  without rehydrating raw payloads.
- Classify Jira fields as `system`, `jira_custom`, or `app_custom` in both `staging_jira_fields` and
  `staging_jira_project_issue_type_fields`, filtering `app_custom` entries out of the custom-field mapping pipeline and
  improving downstream Redmine decisions.
- Harden custom field detection to avoid PHP version quirks, bump the custom field migration script version to `0.0.18`, and
  align the README references.

## [0.0.35] - 2025-12-07

- Iterate over staged Jira projects and their issue types via `/rest/api/3/issue/createmeta` to capture the fields exposed on
  each create screen in `staging_jira_project_issue_type_fields`, including required flags.
- Blend the project/issue-type visibility into context grouping so Redmine proposals include tracker and project scopes even
  when Jira contexts are global.
- Bump the custom field migration script version to `0.0.16` and refresh the README with the new extraction step.

## [0.0.36] - 2025-12-08

- Switch the Jira create-metadata extraction to the scoped endpoints (`/issue/createmeta/{project}/issuetypes[/ {id}]`) so
  `staging_jira_project_issue_type_fields` reliably captures per-project issue type fields on Jira Cloud.
- Add logging around project/issue-type discovery for easier debugging when create permissions or API responses are empty.
- Bump the custom field migration script version to `0.0.17` and refresh the README references.

## [0.0.37] - 2025-12-09

- Capture Jira field schema hints and normalised allowed values in `staging_jira_project_issue_type_fields`, including
  `schema_type`, `schema_custom`, and `allowed_values_json`, so the transform phase can reason about select-like fields
  without rehydrating raw payloads.
- Classify Jira fields as `system`, `jira_custom`, or `app_custom` in both `staging_jira_fields` and
  `staging_jira_project_issue_type_fields`, filtering `app_custom` entries out of the custom-field mapping pipeline and
  improving downstream Redmine decisions.
- Harden custom field detection to avoid PHP version quirks, bump the custom field migration script version to `0.0.18`, and
  align the README references.

## [0.0.33] - 2025-12-05

- Capture Jira issue type usage per project by expanding `/rest/api/3/project/search` and store the associations in
  `staging_jira_issue_type_projects`, defaulting missing scopes to `GLOBAL` so mappings clearly distinguish project-scoped and
  global issue types.
- Derive proposed Redmine project links from the recorded Jira usage, preserving the associations in
  `migration_mapping_trackers.proposed_redmine_project_ids` for both project-scoped and global issue types and marking matched
  trackers as `READY_FOR_UPDATE` when they are missing from the target projects.
- Allow the project-link push plan to use the recorded project lists regardless of Jira scope so existing Redmine trackers are
  added to every mapped project, and bump the tracker CLI version to `0.0.16` with refreshed README guidance.

## [0.0.34] - 2025-12-06

- Add an update path for custom field associations by extending `migration_mapping_custom_fields.migration_status` with
  `READY_FOR_UPDATE`, detecting missing project/tracker links during transform, and persisting the desired associations in the
  proposed columns.
- Collect an association update plan from staging snapshots and apply the merged project/tracker lists to existing Redmine
  custom fields (including cascading parents) through the extended API, updating automation hashes and statuses after
  successful synchronisation.
- Surface the planned association changes in manual mode when the extended API is disabled and bump the custom field migration
  script version to `0.0.14` with refreshed documentation.

## [0.0.32] - 2025-12-04

- Capture Jira issue type scope and project IDs by fetching detailed payloads
  when the list response omits scope metadata, ensuring
  `staging_jira_issue_types.scope_type` and `scope_project_id` are populated.
- Mark matched trackers that need new project associations as
  `READY_FOR_UPDATE` and link them during the push phase instead of treating
  them as creations. Mapped project IDs now feed both the transform and project
  update plan.
- Bump the tracker CLI version to `0.0.15`.

## [0.0.31] - 2025-12-03

- Refresh the tracker snapshot via the extended API when available so project
  links, custom fields, and other tracker attributes are captured in
  `staging_redmine_trackers`, with safe fallbacks and warnings when the plugin
  is unreachable.
- Bump the tracker CLI version to `0.0.14`.

## [0.0.30] - 2025-12-02

- Record the mapped Redmine project IDs for project-scoped trackers in
  `migration_mapping_trackers.proposed_redmine_project_ids` so they can be
  reviewed or overridden before linking.
- Use the recorded project list when planning tracker-to-project updates and
  surface warnings if mappings are missing from the database snapshot.
- Bump the tracker CLI version to `0.0.13`.

## [0.0.29] - 2025-12-01

- Link trackers to their mapped Redmine projects during the push phase, adding
  missing associations for project-scoped Jira issue types after tracker
  creation or when existing trackers already exist. The tracker CLI now reports
  version `0.0.12` and previews the planned project updates during `--dry-run`
  or confirmed runs.

## [0.0.28] - 2025-11-30

- Move attachment metadata harvesting out of the issue extractor and into the
  attachment migration script's Jira phase so staged issues can be reused
  without extra Jira API calls. The attachment CLI now reports version `0.0.17`
  and the issue CLI version is `0.0.27`.
- Refresh the README to document the new ordering (issue extract before the
  attachment Jira phase), the attachment staging behaviour, and the updated
  version numbers.

## [0.0.27] - 2025-11-29

- Extend the custom field transform so it reads the latest usage snapshot,
  auto-ignores Jira custom fields that never appear in staged issues, and
  surfaces the usage statistics in the mapping notes to aid manual reviews.
- First attempt to handle unsupported field types.
- Implement parallel attachment downloads to speed up the migration process.
- Surface an `Ignored (unused)` counter in the transform summary and bump the
  custom field CLI version to `0.0.12`.

## [0.0.26] - 2025-11-28

- Harden the custom field usage phase so it only analyses issues whose raw Jira
  payload is valid JSON, preventing the aggregation query from failing when
  staging data was ingested before the issue extraction script was fixed.

## [0.0.25] - 2025-11-27

- Fix `11_migrate_issues.php` so the Jira extraction phase calls
  `/rest/api/3/search/jql` via query parameters instead of the deprecated POST
  payload, reintroducing the `fieldsByKeys` flag and honouring Jira's reported
  batch size to prevent the `Invalid request payload` errors seen when
  requesting rendered HTML descriptions. The CLI now reports version `0.0.25`.

## [0.0.24] - 2025-11-26

- Capture Jira's rendered HTML descriptions and comment bodies in the staging
  tables, refresh the attachment metadata index, and depend on
  `league/html-to-markdown` so the issue and journal transforms can convert the
  HTML into CommonMark while rewriting Jira attachment links to Redmine's
  `attachment:` syntax.
- Document the new workflow in the README and bump the CLI versions so operators
  know the description/comment Markdown now honours the rendered HTML payloads
  and attachment metadata stored during extraction.

## [0.0.23] - 2025-11-25

- Teach `11_migrate_issues.php` to persist Jira issue link metadata in the new
  `staging_jira_issue_links` table so downstream scripts can reason about
  relation directionality, and relax `staging_jira_attachments.created_at` to
  accept `NULL` values so the association hint fallback works when Jira omits a
  timestamp. The CLI now reports version `0.0.23`.
- Introduce `13_migrate_subtasks.php` to analyse Jira parent/child pairs and
  update the corresponding Redmine `parent_issue_id` assignments with optional
  dry-run previews and automation-hash preservation.
- Add `14_migrate_issue_relations.php` plus the
  `migration_mapping_issue_relations` table to map Jira links to Redmine relation
  types, queue rows for review, and create the final relations via the Redmine
  REST API.
- Document the new workflow in the README, extend the script order table with
  the subtask/relation steps, and describe the new CLIs so operators know how to
  run them before the tags migration.

## [0.0.22] - 2025-11-24

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

## [0.0.21] - 2025-11-24

- Fix the Jira search payload built by `11_migrate_issues.php` so it passes an
  array for both `fields` and `expand`, plus an explicit `fieldsByKeys` flag,
  addressing the Atlassian `Invalid request payload` errors reported after the
  per-project pagination refactor. The CLI now reports version `0.0.21`.
- Update the README option table to reflect the new version number so operators
  can easily confirm they are running the patched build.

## [0.0.20] - 2025-11-24

- Rebuild `11_migrate_issues.php` so the Jira extraction phase iterates over
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

## [0.0.19] - 2025-11-23

- Fix `11_migrate_issues.php` so Jira extraction once again targets
  `/rest/api/3/search/jql`, sending the request payload Atlassian now
  expects (`fields` as an array) while preserving the `ORDER BY id ASC`
  keyset pagination strategy.
- Inject a default `id >= 0` constraint when no custom JQL is supplied so
  the initial search call always satisfies Jira's bounded query
  requirement, and bump the CLI version plus README references to
  `0.0.19`.

## [0.0.18] - 2025-11-22

- Rework `11_migrate_issues.php` so Jira extraction uses keyset pagination
  against `/rest/api/3/search`, automatically enforcing `ORDER BY id ASC` and
  appending `id > <last_seen_id>` filters to stream every issue without
  requiring per-project scoping. The CLI version now reports `0.0.18`.
- Drop the short-lived `migration.issues.project_keys` knob from the default
  configuration, document the new pagination behaviour in the README, and keep
  `migration.issues.jql` as the optional place to add custom filters when
  batching exports.

## [0.0.17] - 2025-11-21

- Enforce bounded Jira searches during issue extraction by teaching
  `11_migrate_issues.php` to require `migration.issues.project_keys` (or a fully
  scoped custom JQL) before calling `/rest/api/3/search/jql`, automatically
  constructing `project in (...) ORDER BY created ASC` queries for bulk project
  migrations.
- Extend `config/config.default.php` and the README with the new
  `migration.issues.project_keys` knob plus guidance on batching projects per
  run so large catalogues can be migrated safely.
- Bump the issue migration CLI version to `0.0.17` and update the documented
  version hints.

## [0.0.16] - 2025-11-20

- Update `11_migrate_issues.php` to call Jira's `/rest/api/3/search/jql` endpoint
  with next-page tokens so the attachments, issues, and journals pipelines no
  longer hit the retired search API.
- Audit the other CLI scripts to ensure no deprecated Jira search endpoints are
  referenced.

## [0.0.15] - 2025-11-19

- Split `10_migrate_attachments.php` into explicit `jira`, `pull`, `transform`,
  and `push` phases with independent confirmations, optional per-phase limits,
  and refreshed CLI help text.
- Allow operators to stage smaller batches by toggling the new
  `download_enabled` / `upload_enabled` flags on `migration_mapping_attachments`
  rows and storing attachment file sizes as `jira_filesize` for reporting.
- Update the README and database schema to document the new columns, workflow,
  and testing guidance, and bump the attachment script version to `0.0.15`.

## [0.0.14] - 2025-11-18

- Introduce `10_migrate_attachments.php` to synchronise attachment mappings,
  download binaries into the working directory, and pre-upload them to Redmine
  while labelling each file for issue or journal association.
- Update `11_migrate_issues.php` to consume pre-generated attachment tokens,
  classify association hints, and reconcile Redmine attachment identifiers after
  issue creation.
- Add `12_migrate_journals.php` to migrate Jira comments and changelog entries,
  converting ADF payloads to notes and reusing journal-scoped attachment tokens.
- Extend the schema with attachment association metadata (`redmine_issue_id`,
  `association_hint`), refresh the README with the new workflows, and bump script
  versions to `0.0.14`.

## [0.0.13] - 2025-11-17

- Teach `11_migrate_issues.php` to download Jira attachments into
  `tmp/attachments/jira`, maintain the `migration_mapping_attachments` workflow,
  and upload binaries to Redmine ahead of issue creation while capturing upload
  tokens for later association.
- Extend the push phase reporting so operators can see pending attachment
  counts during dry runs and successful uploads when `--confirm-push` is used.
- Refresh the README with the new attachment pipeline details, the updated
  script version, and guidance on ensuring the temporary directory is writable.

## [0.0.12] - 2025-11-16

- Streamline the issue migration by dropping the Redmine issue snapshot phase
  and table, keeping the staging schema focused on the Jira catalogue while the
  mapping table records successful Redmine creations.
- Update `11_migrate_issues.php` to run only the Jira, transform, and push
  phases, bump its CLI version, and document the leaner workflow in the README.

## [0.0.11] - 2025-11-15

- Add `11_migrate_issues.php`, completing the issue ETL workflow with Jira
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

## [0.0.10] - 2025-11-14

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

## [0.0.9] - 2025-10-02

- Extend `08_migrate_custom_fields.php` to retrieve Jira field contexts and allowed option values, persisting them
  in the new `staging_jira_field_contexts` table for downstream analysis.
- Expand `migration_mapping_custom_fields` with context metadata (hashes, Jira project/issue type lists, allowed
  values) so the transform phase can reason about per-project option differences.
- Update the transform logic to split divergent Jira contexts into distinct Redmine proposals with context-aware
  names, automatically populate option lists, and map Jira projects/issue types to Redmine projects and trackers.
- Refresh the README with the context-aware workflow, clarify the extended API checklist, and bump all CLI entry
  points to version `0.0.9`.

## [0.0.8] - 2025-10-01

- Add `08_migrate_custom_fields.php`, covering the full extract/transform/push pipeline for Jira custom fields with
  optional automation via the `redmine_extended_api` plugin.
- Enrich the staging and mapping schema for custom fields with metadata columns (schema hints, proposed Redmine
  attributes, automation hashes) so manual overrides persist across reruns and list-style fields can capture
  proposed option values.
- Teach the README about the new script, update the extended API guidance with custom field examples, and bump all
  CLI entry points to `0.0.8`.

## [0.0.7] - 2025-09-30

- Introduce `07_migrate_trackers.php` to reconcile Jira issue types with Redmine trackers, including optional
  creation support through the `redmine_extended_api` plugin.
- Extend the tracker mapping table with Jira metadata, proposed Redmine attributes, and automation hashes so manual
  overrides persist across reruns, and teach the transform phase to derive default status IDs automatically.
- Add `migration.trackers.default_redmine_status_id` to the configuration and document the new workflow in the README.
- Refresh the README examples to cover the tracker automation flow and bump all CLI entry points to `0.0.7`.

## [0.0.6] - 2025-09-29

- Add `05_migrate_statuses.php` to extract Jira statuses, refresh the Redmine snapshot, reconcile mappings with
  automation-hash preservation, and print a manual creation checklist.
- Add `06_migrate_priorities.php` following the same phased workflow for issue priorities, including Jira/Redmine
  upserts, reconciliation, and a manual push summary.
- Extend the staging schema with richer metadata for status and priority mappings (names, proposed values, and
  automation hashes) so manual overrides persist across reruns.
- Bump all CLI entry points to `0.0.6` and document the new scripts and workflow guidance in the README.

## [0.0.5] - 2025-09-28

- Introduce `04_migrate_roles.php` to reconcile Jira project roles with Redmine projects, groups, and roles, including a manual
  push checklist for assigning groups to projects.
- Extend the staging schema with `staging_jira_project_role_actors`, enrich `migration_mapping_roles`, and add `migration_mapping_project_role_groups` to track per-project role memberships.
- Add `migration.roles.default_redmine_role_id` configuration support and expand the README with workflow guidance for the new script.
- Bump all CLI entry points to `0.0.5` and refresh the documentation to reflect the updated version and role-mapping capabilities.
- Capture Redmine group project role memberships in staging and automatically mark existing assignments as already recorded during the role transform.

## [0.0.4] - 2025-09-27

- Add `01_migrate_projects.php`, covering the full extract/transform/push workflow for Redmine projects (with dry-run previews and creation support).
- Promote project synchronisation to the first migration step and renumber the user and group scripts to `02_migrate_users.php` and `03_migrate_groups.php` respectively.
- Bump the CLI script versions to `0.0.4` and refresh the README to document the new entry point, updated command names, and workflow ordering.

## [0.0.3] - 2025-09-26

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

## [0.0.2] - 2025-09-25

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

## [0.0.1] - 2025-09-24

- Initial commit
- Add composer dependencies
- Add a first migration schema
- Add a README.md
- Add a configuration logic
- Retrieve users from Jira
- Retrieve users from Redmine
- Add CLI controls, docs, and changelog for the user migration script
