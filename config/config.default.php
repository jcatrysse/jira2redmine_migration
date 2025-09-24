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
        // Redmine REST API key with sufficient permissions for the migration.
        'api_key' => 'your-redmine-api-key',
    ],
    'database' => [
        // MySQL connection string pointing to the staging database that stores the migration state.
        'dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=jira2redmine;charset=utf8mb4',
        // Database user that has permissions to read/write the staging tables.
        'username' => 'jira2redmine',
        // Password for the database user above.
        'password' => 'change-me',
        // Optional PDO constructor options.
        'options' => [],
    ],
    'paths' => [
        // Directory used to store temporary files such as API exports or attachments.
        // Defaults to ./tmp relative to the project root.
        'tmp' => dirname(__DIR__) . '/tmp',
    ],
    'migration' => [
        'users' => [
            // Default Redmine status to propose for new accounts when no existing Redmine user is matched.
            // Allowed values: LOCKED, ACTIVE.
            'default_redmine_user_status' => 'LOCKED',
        ],
    ],
];
