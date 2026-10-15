<?php

return [
    'jira' => [
        // Base URL of the Jira tenant, e.g. https://your-domain.atlassian.net
        'base_url' => 'https://your-jira-instance.example.com',
        // Jira user (email address) that owns the API token used for authentication.
        'username' => 'jira-user@example.com',
        // Jira API token generated for the user above.
        'api_token' => 'your-jira-api-token',
        // Cloud id of the site (https://<site>/_edge/tenant_info). When set, every API call goes through
        // the gateway https://api.atlassian.com/ex/jira/{cloud_id}, which scoped API tokens require.
        // base_url stays the site URL (links back to Jira). Leave null for a classic (unscoped) token.
        'cloud_id' => null,
    ],
    'redmine' => [
        // Base URL of the Redmine instance, e.g. https://redmine.example.com
        'base_url' => 'https://your-redmine-instance.example.com',
        // The Redmine REST API key with sufficient permissions for the migration.
        'api_key' => 'your-redmine-api-key',
        // Optional support for the redmine_extended_api plugin.
        'extended_api' => [
            // When true, push phases can automatically create records using the extended API.
            'enabled' => false,
            // Change this if the plugin is mounted under a custom prefix.
            'prefix' => '/extended_api',
        ],
    ],
    'database' => [
        // MySQL connection string pointing to the staging database that stores the migration state.
        'dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=jira2redmine;charset=utf8mb4',
        // Database user that has permissions to read/write the staging tables.
        'username' => 'jira2redmine',
        // The password for the database user above.
        'password' => 'change-me',
        // Optional PDO constructor options.
        'options' => [],
    ],
    'paths' => [
        // Directory used to store temporary files such as API exports or attachments.
        // Absolute paths are respected (e.g. /tmp), otherwise paths are resolved relative to the project root.
        'tmp' => dirname(__DIR__) . '/tmp',
    ],
    'migration' => [
        'users' => [
            // Default Redmine status to propose for new accounts when no existing Redmine user is matched.
            // Allowed values: LOCKED, ACTIVE.
            'default_redmine_user_status' => 'LOCKED',
            // Optional Redmine authentication mode identifier (e.g. LDAP) to assign to newly created users.
            // Leave as null to keep Redmine's default (password-based) authentication mode.
            'auth_source_id' => null,
        ],
        'roles' => [
            // Optional Redmine role identifier to fall back to when no automatic match is found for a Jira project role.
            // Leave as null to require manual selection of the target Redmine role per assignment.
            'default_redmine_role_id' => null,
            // Jira project role name => Redmine role name; null ignores the role (no membership).
            // Jira Cloud's own role names never match Redmine's; roles not listed are matched by their own name.
            // Jan, decision 12 (2026-10-14): during the import every migrated user is a member of every migrated
            // project with a temporary role (the "members" step), removed again by "restore". So 04 proposes no
            // assignee-only (B46) or watcher-only (B50) members by default; true turns them on, with this role.
            'assignee_only_members' => false,
            'assignee_only_role' => 'Developer',
            'watcher_only_members' => false,
            'watcher_only_role' => 'Developer',
            'mappings' => [
                'Administrators' => 'Manager',
                // holds only app accounts (Jira apps), which are not migrated
                'atlassian-addons-project-access' => null,
                // group grants of the project's permission scheme (B31, Jan 2026-10-13)
                'Browse projects (permission scheme)' => 'Developer',
                'Assignable user (permission scheme)' => 'Developer',
                'Administer projects (permission scheme)' => 'Manager',
            ],
        ],
        'projects' => [
            // Jan, decision 22 (2026-10-14): Jira project keys that are not migrated; 01 marks them IGNORED and
            // does not create them (TEST is a test project).
            'not_migrated' => ['TEST'],
        ],
        'custom_fields' => [
            // Jan, decision 20 (2026-10-14): Jira field types that are not migrated; 08 marks them IGNORED (not
            // MANUAL), their values stay in Jira only. The service desk fields "Organizations" and "Request
            // participants".
            'not_migrated' => [
                'com.atlassian.servicedesk:sd-customer-organizations',
                'com.atlassian.servicedesk:sd-request-participants',
            ],
        ],
        // tools/workflow_plan.php (Jan, decision 13): 'project' gives each project its own Jira workflow through
        // redmine_project_workflows, with the union cleaned by Jan as the global workflow; 'union' writes only the
        // global workflow (Redmine without that plugin).
        'workflows' => [
            'mode' => 'project',
            // Jan, decision 14 (2026-10-14): a transition Jira limits to a group that is not a member of the project.
            // Per project key and Jira group: 'G' makes the group a member (04) with its own role, which gets the
            // transitions; 'R' gives them to the roles the group's users hold in the project; 'ignore' does not carry
            // them. A group not listed gives review rows (role 0) for Jan, as before.
            'group_roles' => [
                'DOC' => ['DOC Support' => 'G'],
                'FPS' => ['GEOxyz- Crewing approvers' => 'G'],
                'GEOS' => ['Survey Support' => 'G', 'atlassian-addons-admin' => 'ignore'],
                'ICT' => ['ICT Support' => 'G', 'GEOxyz-Jira-User' => 'R'],
                'LLE' => ['GEOxyz-Jira-User' => 'R'],
                'SIS' => ['GEOxyz-Jira-User' => 'R'],
                'SOIL' => ['atlassian-addons-admin' => 'ignore'],
                'SURV' => ['atlassian-addons-admin' => 'ignore'],
                'TEST' => ['GEOxyz-Jira-User' => 'R', 'Survey Support' => 'R'],
            ],
            // G: the name of the group's own role (%s: the Jira group) and its permissions. Redmine applies a role's
            // workflow only with "Edit issues" or "Add issues"; no "View issues" and not assignable, so the role
            // gives nothing but the transitions to a user who sees the project's issues through another role.
            'group_role_name' => '%s (workflow)',
            'group_role_permissions' => ['edit_issues'],
            // Jan, decision 19: Jira conditions Redmine cannot carry, left out on purpose per project key; the
            // transition is proposed without them (FPS: "Screen" only with one option of a select field).
            'dropped_conditions' => ['FPS' => ['ValueFieldCondition']],
        ],
        // Jan, decision 35 (2026-10-15): one priority scheme for every project (redmine_itil_priority computes the
        // priority from impact and urgency). A Jira priority maps onto an existing Redmine priority: the one named
        // here (Jira name => Redmine name), else the one of the same name; anything else is a review row
        // (MANUAL) to map by hand. 06 creates a priority only with create_missing (B73: after the existing ones).
        'priorities' => [
            'mappings' => [],
            'create_missing' => false,
        ],
        'trackers' => [
            // Optional Redmine status identifier to propose when creating new trackers via the extended API.
            // Leave as null to derive the lowest open status from the staging snapshot.
            'default_redmine_status_id' => null,
        ],
        'issues' => [
            // Optional JQL filter AND-ed with each automatic `project = "KEY"` clause before `ORDER BY id ASC`.
            // Provide any additional constraints (date range, issue types, etc.) or leave blank to export every issue per project.
            'jql' => '',
            // Jira project keys whose issues are extracted, e.g. ['GEOS', 'SIS']; empty for every project.
            // Projects left out are not marked as extracted, so they can be added in a later run.
            'projects' => [],
            // Number of issues requested per Jira search page (max 100).
            'batch_size' => 100,
            // Optional base URL used to link back to the original Jira issue in migrated descriptions.
            // Defaults to the Jira base_url when left null.
            'jira_issue_base_url' => null,
            // Optional Redmine identifiers used as fallbacks when Jira data cannot be matched automatically.
            'default_redmine_project_id' => null,
            'default_redmine_tracker_id' => null,
            'default_redmine_status_id' => null,
            'default_redmine_priority_id' => null,
            'default_redmine_author_id' => null,
            'default_redmine_assignee_id' => null,
            // When set, overrides the privacy flag proposed during the transform phase (true/false/null).
            'default_is_private' => null,
            // Status behaviour for an open subtask under a closed parent (Redmine refuses that link).
            // open_parent (default, decided 2026-10-13): the parent gets subtask_open_parent_status_name.
            // close_subtasks: close open subtasks when the parent is closed.
            // null: leave it; the subtask is created without its parent.
            'subtask_status_behavior' => 'open_parent',
            // Status ID to apply to subtasks when subtask_status_behavior = close_subtasks.
            'subtask_close_status_id' => null,
            // Status for those parents: an id, or (when null) the open Redmine status with this name.
            'subtask_open_parent_status_id' => null,
            'subtask_open_parent_status_name' => 'Resolved',
        ],
        'journals' => [
            // Optional Redmine user identifier used when a comment/changelog author cannot be mapped from Jira.
            // Set to null to skip author overrides when using the extended API.
            'default_redmine_author_id' => null,
            // Seconds around a Jira comment within which uploaded attachments are listed under it as "> attachment:"
            // lines (B59). 0 lists none: images the comment shows stay inline and the issue lists every attachment.
            'comment_attachment_window' => 0,
        ],
        'relations' => [
            // Optional custom mappings for Jira issue link types to Redmine relation types (including plugin-defined types).
            // Keys are Jira link type names, values define the Redmine relation type to use for outward/inward directions.
            // Leave empty to fall back to default Redmine relation mapping (e.g. "relates").
            'custom_mappings' => [
                'To Wiki' => [
                    'outward' => 'relates_to_wiki',
                    'inward' => 'relates_from_wiki',
                ],
                'To Labo Defective Equipment' => [
                    'outward' => 'relates_to_labo_defective_eq',
                    'inward' => 'relates_from_labo_defective_eq',
                ],
                'To Labo Task' => [
                    'outward' => 'relates_to_labo_task',
                    'inward' => 'relates_from_labo_task',
                ],
                'Create RMA' => [
                    'outward' => 'relates_to_create_rma',
                    'inward' => 'relates_from_create_rma',
                ],
                'To DATA Support' => [
                    'outward' => 'relates_to_data_support',
                    'inward' => 'relates_from_data_support',
                ],
                'Polaris work item link' => [
                    'outward' => 'relates_to_polaris_work_item',
                    'inward' => 'relates_from_polaris_work_item',
                ],
                'Cloners' => [
                    'outward' => 'relates_to_cloners',
                    'inward' => 'relates_from_cloners',
                ],
            ],
        ],
        'checklists' => [
            // Primary rendered field storing checklist lines (e.g. "* [open] item").
            'primary_rendered_field' => 'customfield_10083',
            // Fallback field containing checklist ADF payload.
            'fallback_field' => 'customfield_10160',
        ],
    ],
    'attachments' => [
        // Number of concurrent download workers for Jira attachments; increase to speed up pulls if network allows.
        'download_concurrency' => 1,
        // Test option, off by default: download and upload only every attachment up to 512 KB plus, per file
        // extension, the largest one up to 20 MB; the rest is noted "skipped by sample" (volume test runs).
        'test_sample' => false,
        // Optional Redmine user identifier used for attachment uploads when the Jira author cannot be mapped.
        // Set to null to avoid overriding the uploader when using the extended API.
        'default_redmine_author_id' => null,
        // Optional SharePoint configuration used to offload large attachments instead of sending them to Redmine.
        'sharepoint' => [
            // Minimum size in bytes before attachments are uploaded to SharePoint instead of Redmine.
            // Set to null to disable SharePoint uploads entirely.
            'offload_threshold_bytes' => null,
            // Azure AD tenant identifier used for the OAuth client credential flow.
            'tenant_id' => 'your-tenant-id',
            // Azure AD application (client) identifier with permissions to upload to the target SharePoint drive.
            'client_id' => 'your-client-id',
            // Azure AD client secret for the application above.
            'client_secret' => 'your-client-secret',
            // Target the SharePoint site and drive that will receive uploaded files.
            'site_id' => 'your-site-id',
            'drive_id' => 'your-drive-id',
            // Folder path within the drive where attachments should be written.
            'folder_path' => 'Shared Documents/Attachments',
            // Optional override for the Microsoft Graph API base URL.
            'graph_base_url' => 'https://graph.microsoft.com/v1.0',
            // Upload chunk size (in bytes) used for the Graph upload session; defaults to 5 MiB.
            'chunk_size_bytes' => 5 * 1024 * 1024,
        ],
    ],
];
