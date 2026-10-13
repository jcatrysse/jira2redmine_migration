<?php

declare(strict_types=1);

namespace Jira2Redmine\Http;

use GuzzleHttp\Client;
use RuntimeException;

/**
 * The one place that builds the Jira and Redmine HTTP clients for the migration scripts.
 */
final class ClientFactory
{
    /**
     * Jira client: the site URL as base_uri, Basic auth with the API token, and the gateway rewrite of
     * lib/jira_gateway.php when jira.cloud_id is set.
     *
     * @param array<string, mixed> $jiraConfig
     * @param float|null $timeout seconds; null keeps Guzzle's default (no timeout)
     */
    public static function jira(array $jiraConfig, ?float $timeout = null): Client
    {
        $baseUrl = trim((string)($jiraConfig['base_url'] ?? ''));
        $username = (string)($jiraConfig['username'] ?? '');
        $apiToken = (string)($jiraConfig['api_token'] ?? '');

        if ($baseUrl === '' || $username === '' || $apiToken === '') {
            throw new RuntimeException('Jira configuration must include base_url, username, and api_token.');
        }

        $options = [
            'base_uri' => rtrim($baseUrl, '/') . '/',
            'handler' => \jiraHandlerStack($jiraConfig),
            'auth' => [$username, $apiToken],
            'headers' => [
                'Accept' => 'application/json',
            ],
        ];
        if ($timeout !== null) {
            $options['timeout'] = $timeout;
        }

        return new Client($options);
    }

    /**
     * Redmine client: base_uri with a trailing slash (keeps a sub-path such as /redmine for relative
     * paths), the API key header.
     *
     * @param array<string, mixed> $redmineConfig
     * @param float|null $timeout seconds; null keeps Guzzle's default (no timeout)
     */
    public static function redmine(array $redmineConfig, ?float $timeout = null): Client
    {
        $baseUrl = trim((string)($redmineConfig['base_url'] ?? ''));
        $apiKey = (string)($redmineConfig['api_key'] ?? '');

        if ($baseUrl === '' || $apiKey === '') {
            throw new RuntimeException('Redmine configuration must include base_url and api_key.');
        }

        $options = [
            'base_uri' => rtrim($baseUrl, '/') . '/',
            'headers' => [
                'Accept' => 'application/json',
                'X-Redmine-API-Key' => $apiKey,
            ],
        ];
        if ($timeout !== null) {
            $options['timeout'] = $timeout;
        }

        return new Client($options);
    }
}
