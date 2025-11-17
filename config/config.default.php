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
            // Optional Redmine identifiers used as fallbacks when Jira data cannot be matched automatically.
            'default_redmine_project_id' => null,
            'default_redmine_tracker_id' => null,
            'default_redmine_status_id' => null,
            'default_redmine_priority_id' => null,
            'default_redmine_author_id' => null,
            'default_redmine_assignee_id' => null,
            // When set, overrides the privacy flag proposed during the transform phase (true/false/null).
            'default_is_private' => null,
        ],
    ],
    'attachments' => [
        // Number of concurrent download workers for Jira attachments; increase to speed up pulls if network allows.
        'download_concurrency' => 1,
    ],
];
