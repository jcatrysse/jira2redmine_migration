<?php

return [
    'jira' => [
        // Base URL of the Jira tenant, e.g. https://your-domain.atlassian.net
        'base_url' => 'https://your-jira-instance.example.com',
        // Jira user (email address) that owns the API token used for authentication.
        'username' => 'jira-user@example.com',
        // Jira API token generated for the user above.
        'api_token' => 'your-jira-api-token',
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
            // Optional status behavior when linking subtasks to parents.
            // null: ignore status conflicts (default).
            // close_subtasks: close open subtasks when the parent is closed.
            // open_parent: reopen the parent when linking an open subtask.
            'subtask_status_behavior' => null,
            // Status ID to apply to subtasks when subtask_status_behavior = close_subtasks.
            'subtask_close_status_id' => null,
            // Status ID to apply to parents when subtask_status_behavior = open_parent.
            'subtask_open_parent_status_id' => null,
        ],
        'journals' => [
            // Optional Redmine user identifier used when a comment/changelog author cannot be mapped from Jira.
            // Set to null to skip author overrides when using the extended API.
            'default_redmine_author_id' => null,
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
