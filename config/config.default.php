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
    'paths' => [
        // Directory used to store temporary files such as API exports or attachments.
        // Defaults to ./tmp relative to the project root.
        'tmp' => dirname(__DIR__) . '/tmp',
    ],
];
