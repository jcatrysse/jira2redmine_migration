<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('This script is intended to be run from the command line.');
}

/** @var array<string, mixed> $config */
$config = require __DIR__ . '/bootstrap.php';

try {
    main($config);
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf("[ERROR] %s%s", $exception->getMessage(), PHP_EOL));
    exit(1);
}

/**
 * @param array<string, mixed> $config
 */
function main(array $config): void
{
    $jiraConfig = extractArrayConfig($config, 'jira');
    $databaseConfig = extractArrayConfig($config, 'database');

    $pdo = createDatabaseConnection($databaseConfig);
    $client = createJiraClient($jiraConfig);

    printf("[%s] Starting Jira user extraction...%s", (new DateTimeImmutable())->format(DateTimeImmutable::ATOM), PHP_EOL);

    $totalProcessed = fetchAndStoreJiraUsers($client, $pdo);

    printf("[%s] Completed extraction. %d user records processed.%s", (new DateTimeImmutable())->format(DateTimeImmutable::ATOM), $totalProcessed, PHP_EOL);
}

/**
 * @param array<string, mixed> $config
 * @return array<string, mixed>
 */
function extractArrayConfig(array $config, string $key): array
{
    if (!array_key_exists($key, $config) || !is_array($config[$key])) {
        throw new RuntimeException(sprintf('Missing or invalid configuration section: %s', $key));
    }

    return $config[$key];
}

/**
 * @param array<string, mixed> $databaseConfig
 */
function createDatabaseConnection(array $databaseConfig): PDO
{
    $dsn = trim((string)($databaseConfig['dsn'] ?? ''));
    $username = (string)($databaseConfig['username'] ?? '');
    $password = (string)($databaseConfig['password'] ?? '');

    if ($dsn === '') {
        throw new RuntimeException('Database DSN is not configured.');
    }

    $options = $databaseConfig['options'] ?? [];
    if (!is_array($options)) {
        throw new RuntimeException('Database options configuration must be an array.');
    }

    $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
    $options[PDO::ATTR_DEFAULT_FETCH_MODE] = PDO::FETCH_ASSOC;
    $options[PDO::ATTR_EMULATE_PREPARES] = false;

    try {
        $pdo = new PDO($dsn, $username, $password, $options);
    } catch (PDOException $exception) {
        throw new RuntimeException('Unable to connect to the database: ' . $exception->getMessage(), 0, $exception);
    }

    return $pdo;
}

/**
 * @param array<string, mixed> $jiraConfig
 */
function createJiraClient(array $jiraConfig): Client
{
    $baseUrl = trim((string)($jiraConfig['base_url'] ?? ''));
    $username = (string)($jiraConfig['username'] ?? '');
    $apiToken = (string)($jiraConfig['api_token'] ?? '');

    if ($baseUrl === '' || $username === '' || $apiToken === '') {
        throw new RuntimeException('Jira configuration must include base_url, username, and api_token.');
    }

    return new Client([
        'base_uri' => rtrim($baseUrl, '/') . '/',
        'auth' => [$username, $apiToken],
        'headers' => [
            'Accept' => 'application/json',
        ],
        'timeout' => 30,
    ]);
}

function fetchAndStoreJiraUsers(Client $client, PDO $pdo): int
{
    $maxResults = 100;
    $startAt = 0;
    $totalProcessed = 0;

    $insertStatement = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_users (account_id, account_type, display_name, email_address, is_active, group_memberships, raw_payload, extracted_at)
        VALUES (:account_id, :account_type, :display_name, :email_address, :is_active, :group_memberships, :raw_payload, :extracted_at)
        ON DUPLICATE KEY UPDATE
            account_type = VALUES(account_type),
            display_name = VALUES(display_name),
            email_address = VALUES(email_address),
            is_active = VALUES(is_active),
            group_memberships = VALUES(group_memberships),
            raw_payload = VALUES(raw_payload),
            extracted_at = VALUES(extracted_at)
    SQL);

    while (true) {
        try {
            $response = $client->get('/rest/api/3/users/search', [
                'query' => [
                    'startAt' => $startAt,
                    'maxResults' => $maxResults,
                    'includeInactiveUsers' => 'true',
                    'includeActiveUsers' => 'true',
                    'expand' => 'groups',
                ],
            ]);
        } catch (GuzzleException $exception) {
            throw new RuntimeException('Failed to fetch users from Jira: ' . $exception->getMessage(), 0, $exception);
        }

        try {
            $decoded = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to decode Jira response payload: ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Unexpected response from Jira when fetching users.');
        }

        $batchCount = count($decoded);
        if ($batchCount === 0) {
            break;
        }

        $pdo->beginTransaction();

        try {
            $extractedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            foreach ($decoded as $user) {
                if (!is_array($user)) {
                    continue;
                }

                $accountId = isset($user['accountId']) ? trim((string)$user['accountId']) : '';
                if ($accountId === '') {
                    continue;
                }

                $displayName = isset($user['displayName']) && is_string($user['displayName']) && $user['displayName'] !== ''
                    ? $user['displayName']
                    : $accountId;
                if (function_exists('mb_substr')) {
                    $displayName = mb_substr($displayName, 0, 255);
                } else {
                    $displayName = substr($displayName, 0, 255);
                }

                $accountType = null;
                if (isset($user['accountType']) && is_string($user['accountType']) && $user['accountType'] !== '') {
                    $accountType = function_exists('mb_substr')
                        ? mb_substr($user['accountType'], 0, 100)
                        : substr($user['accountType'], 0, 100);
                }

                $emailAddress = null;
                if (isset($user['emailAddress']) && is_string($user['emailAddress']) && $user['emailAddress'] !== '') {
                    $emailAddress = function_exists('mb_substr')
                        ? mb_substr($user['emailAddress'], 0, 255)
                        : substr($user['emailAddress'], 0, 255);
                }

                $isActive = isset($user['active']) ? (int)((bool)$user['active']) : 0;

                $groupMemberships = null;
                if (isset($user['groups']) && is_array($user['groups'])) {
                    try {
                        $groupMemberships = json_encode($user['groups'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    } catch (JsonException $exception) {
                        throw new RuntimeException('Failed to encode Jira user groups payload: ' . $exception->getMessage(), 0, $exception);
                    }
                }

                try {
                    $rawPayload = json_encode($user, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } catch (JsonException $exception) {
                    throw new RuntimeException('Failed to encode Jira user payload: ' . $exception->getMessage(), 0, $exception);
                }

                $insertStatement->execute([
                    'account_id' => $accountId,
                    'account_type' => $accountType,
                    'display_name' => $displayName,
                    'email_address' => $emailAddress,
                    'is_active' => $isActive,
                    'group_memberships' => $groupMemberships,
                    'raw_payload' => $rawPayload,
                    'extracted_at' => $extractedAt,
                ]);
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        $totalProcessed += $batchCount;
        $startAt += $batchCount;

        printf("Processed %d Jira users (total: %d).%s", $batchCount, $totalProcessed, PHP_EOL);

        if ($batchCount < $maxResults) {
            break;
        }
    }

    return $totalProcessed;
}

// Further transformation and load steps will be implemented in subsequent iterations of this script.
