<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

const MIGRATE_USERS_SCRIPT_VERSION = '0.0.1';
const AVAILABLE_PHASES = [
    'jira' => 'Extract users from Jira and persist them into staging_jira_users.',
    'redmine' => 'Refresh the staging_redmine_users snapshot from the Redmine REST API.',
];

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('This script is intended to be run from the command line.');
}

$cliArguments = $_SERVER['argv'] ?? ($GLOBALS['argv'] ?? null);
if (!is_array($cliArguments)) {
    throw new RuntimeException('Unable to access the command-line arguments.');
}

try {
    [$cliOptions, $positionalArguments] = parseCommandLineOptions($cliArguments);

    if (!empty($cliOptions['help'])) {
        printUsage();
        exit(0);
    }

    if (!empty($cliOptions['version'])) {
        printVersion();
        exit(0);
    }

    if ($positionalArguments !== []) {
        throw new RuntimeException(sprintf(
            'Unexpected positional arguments: %s',
            implode(', ', $positionalArguments)
        ));
    }

    /** @var array<string, mixed> $config */
    $config = require __DIR__ . '/bootstrap.php';

    main($config, $cliOptions);
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf('[ERROR] %s%s', $exception->getMessage(), PHP_EOL));
    exit(1);
}

/**
 * @param array<string, mixed> $config
 * @param array{help: bool, version: bool, phases: ?string, skip: ?string} $cliOptions
 */
function main(array $config, array $cliOptions): void
{
    $phasesToRun = determinePhasesToRun($cliOptions);

    $phaseSummary = [];
    foreach ($phasesToRun as $phaseKey) {
        $phaseSummary[] = $phaseKey;
    }

    printf(
        "[%s] Selected phases: %s%s",
        (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
        implode(', ', $phaseSummary),
        PHP_EOL
    );

    $databaseConfig = extractArrayConfig($config, 'database');
    $pdo = createDatabaseConnection($databaseConfig);

    if (in_array('jira', $phasesToRun, true)) {
        $jiraConfig = extractArrayConfig($config, 'jira');
        $jiraClient = createJiraClient($jiraConfig);

        printf("[%s] Starting Jira user extraction...%s", (new DateTimeImmutable())->format(DateTimeImmutable::ATOM), PHP_EOL);

        $totalJiraProcessed = fetchAndStoreJiraUsers($jiraClient, $pdo);

        printf(
            "[%s] Completed Jira extraction. %d user records processed.%s",
            (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
            $totalJiraProcessed,
            PHP_EOL
        );
    } else {
        printf(
            "[%s] Skipping Jira user extraction (disabled via CLI option).%s",
            (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
            PHP_EOL
        );
    }

    if (in_array('redmine', $phasesToRun, true)) {
        $redmineConfig = extractArrayConfig($config, 'redmine');
        $redmineClient = createRedmineClient($redmineConfig);

        printf("[%s] Starting Redmine user snapshot...%s", (new DateTimeImmutable())->format(DateTimeImmutable::ATOM), PHP_EOL);

        $totalRedmineProcessed = fetchAndStoreRedmineUsers($redmineClient, $pdo);

        printf(
            "[%s] Completed Redmine snapshot. %d user records processed.%s",
            (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
            $totalRedmineProcessed,
            PHP_EOL
        );
    } else {
        printf(
            "[%s] Skipping Redmine user snapshot (disabled via CLI option).%s",
            (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
            PHP_EOL
        );
    }
}

/**
 * @param array{help: bool, version: bool, phases: ?string, skip: ?string} $cliOptions
 * @return array<int, string>
 */
function determinePhasesToRun(array $cliOptions): array
{
    $availablePhaseKeys = array_keys(AVAILABLE_PHASES);
    $phasesToRun = $availablePhaseKeys;

    if (array_key_exists('phases', $cliOptions) && $cliOptions['phases'] !== null) {
        if ($cliOptions['phases'] === '') {
            throw new RuntimeException('The --phases option was provided without any values.');
        }

        $phasesToRun = normalizePhaseList($cliOptions['phases'], $availablePhaseKeys);
    }

    if (array_key_exists('skip', $cliOptions) && $cliOptions['skip'] !== null) {
        if ($cliOptions['skip'] === '') {
            throw new RuntimeException('The --skip option was provided without any values.');
        }

        $phasesToSkip = normalizePhaseList($cliOptions['skip'], $availablePhaseKeys);
        $phasesToRun = array_values(array_diff($phasesToRun, $phasesToSkip));
    }

    if ($phasesToRun === []) {
        throw new RuntimeException('No phases remain to execute after applying the provided CLI options.');
    }

    return $phasesToRun;
}

/**
 * @param array<int, string> $argv
 * @return array{0: array{help: bool, version: bool, phases: ?string, skip: ?string}, 1: array<int, string>}
 */
function parseCommandLineOptions(array $argv): array
{
    $options = [
        'help' => false,
        'version' => false,
        'phases' => null,
        'skip' => null,
    ];

    $positionalArguments = [];
    $argumentCount = count($argv);

    for ($index = 1; $index < $argumentCount; $index++) {
        $argument = $argv[$index];

        if ($argument === '--') {
            for ($nextIndex = $index + 1; $nextIndex < $argumentCount; $nextIndex++) {
                $positionalArguments[] = $argv[$nextIndex];
            }

            break;
        }

        if ($argument === '-h' || $argument === '--help') {
            $options['help'] = true;
            continue;
        }

        if ($argument === '-V' || $argument === '--version') {
            $options['version'] = true;
            continue;
        }

        if ($argument === '--phases') {
            $index++;
            if ($index >= $argumentCount) {
                throw new RuntimeException('The --phases option requires a value.');
            }

            $options['phases'] = trim((string)$argv[$index]);
            continue;
        }

        if (strpos($argument, '--phases=') === 0) {
            $options['phases'] = trim(substr($argument, 9));
            continue;
        }

        if ($argument === '--skip') {
            $index++;
            if ($index >= $argumentCount) {
                throw new RuntimeException('The --skip option requires a value.');
            }

            $options['skip'] = trim((string)$argv[$index]);
            continue;
        }

        if (strpos($argument, '--skip=') === 0) {
            $options['skip'] = trim(substr($argument, 7));
            continue;
        }

        if ($argument !== '' && $argument[0] === '-') {
            throw new RuntimeException(sprintf('Unknown option provided: %s', $argument));
        }

        $positionalArguments[] = $argument;
    }

    return [$options, $positionalArguments];
}

/**
 * @param string $value
 * @param array<int, string> $availablePhases
 * @return array<int, string>
 */
function normalizePhaseList(string $value, array $availablePhases): array
{
    $segments = preg_split('/[\s,]+/', trim($value)) ?: [];
    $segments = array_filter($segments, static fn(string $segment): bool => $segment !== '');

    if ($segments === []) {
        throw new RuntimeException(
            sprintf('No valid phases supplied. Valid phases: %s', implode(', ', $availablePhases))
        );
    }

    $normalized = [];
    foreach ($segments as $segment) {
        $normalizedSegment = strtolower($segment);
        if (!in_array($normalizedSegment, $availablePhases, true)) {
            throw new RuntimeException(
                sprintf('Unknown phase "%s". Valid phases: %s', $segment, implode(', ', $availablePhases))
            );
        }

        if (!in_array($normalizedSegment, $normalized, true)) {
            $normalized[] = $normalizedSegment;
        }
    }

    return $normalized;
}

function printUsage(): void
{
    $scriptName = basename(__FILE__);

    echo sprintf(
        "%s (version %s)%s",
        $scriptName,
        MIGRATE_USERS_SCRIPT_VERSION,
        PHP_EOL
    );
    echo sprintf('Usage: php %s [options]%s', $scriptName, PHP_EOL);
    echo PHP_EOL;
    echo "Options:" . PHP_EOL;
    echo "  -h, --help           Display this help message and exit." . PHP_EOL;
    echo "  -V, --version        Print the script version and exit." . PHP_EOL;
    echo "      --phases=LIST    Comma-separated list of phases to execute." . PHP_EOL;
    echo "      --skip=LIST      Comma-separated list of phases to skip." . PHP_EOL;
    echo PHP_EOL;
    echo "Available phases:" . PHP_EOL;
    foreach (AVAILABLE_PHASES as $phase => $description) {
        echo sprintf("  %-7s %s%s", $phase, $description, PHP_EOL);
    }
    echo PHP_EOL;
    echo "Examples:" . PHP_EOL;
    echo sprintf('  php %s --help%s', $scriptName, PHP_EOL);
    echo sprintf('  php %s --phases=redmine%s', $scriptName, PHP_EOL);
    echo sprintf('  php %s --skip=jira%s', $scriptName, PHP_EOL);
    echo PHP_EOL;
}

function printVersion(): void
{
    printf('%s version %s%s', basename(__FILE__), MIGRATE_USERS_SCRIPT_VERSION, PHP_EOL);
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

/**
 * @param array<string, mixed> $redmineConfig
 */
function createRedmineClient(array $redmineConfig): Client
{
    $baseUrl = trim((string)($redmineConfig['base_url'] ?? ''));
    $apiKey = (string)($redmineConfig['api_key'] ?? '');

    if ($baseUrl === '' || $apiKey === '') {
        throw new RuntimeException('Redmine configuration must include base_url and api_key.');
    }

    return new Client([
        'base_uri' => rtrim($baseUrl, '/') . '/',
        'headers' => [
            'X-Redmine-API-Key' => $apiKey,
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

function fetchAndStoreRedmineUsers(Client $client, PDO $pdo): int
{
    try {
        $pdo->exec('TRUNCATE TABLE staging_redmine_users');
    } catch (PDOException $exception) {
        throw new RuntimeException('Unable to truncate staging_redmine_users: ' . $exception->getMessage(), 0, $exception);
    }

    $limit = 100;
    $offset = 0;
    $totalProcessed = 0;

    $insertStatement = $pdo->prepare(<<<SQL
        INSERT INTO staging_redmine_users (id, login, firstname, lastname, mail, status, raw_payload, retrieved_at)
        VALUES (:id, :login, :firstname, :lastname, :mail, :status, :raw_payload, :retrieved_at)
    SQL);

    while (true) {
        try {
            $response = $client->get('users.json', [
                'query' => [
                    'limit' => $limit,
                    'offset' => $offset,
                    'status' => '*',
                ],
            ]);
        } catch (GuzzleException $exception) {
            throw new RuntimeException('Failed to fetch users from Redmine: ' . $exception->getMessage(), 0, $exception);
        }

        try {
            $decoded = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to decode Redmine response payload: ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($decoded) || !isset($decoded['users']) || !is_array($decoded['users'])) {
            throw new RuntimeException('Unexpected response from Redmine when fetching users.');
        }

        $users = $decoded['users'];
        $batchCount = count($users);

        if ($batchCount === 0) {
            break;
        }

        $rowsToInsert = [];

        foreach ($users as $userSummary) {
            if (!is_array($userSummary)) {
                continue;
            }

            $userId = isset($userSummary['id']) ? (int)$userSummary['id'] : 0;
            if ($userId <= 0) {
                continue;
            }

            $userDetails = fetchRedmineUserDetail($client, $userId);

            $login = normalizeString($userDetails['login'] ?? null, 255);
            if ($login === null) {
                $login = normalizeString($userSummary['login'] ?? null, 255);
            }
            if ($login === null) {
                throw new RuntimeException(sprintf('Redmine user %d is missing a login attribute.', $userId));
            }

            $firstname = normalizeString($userDetails['firstname'] ?? null, 255);
            if ($firstname === null) {
                $firstname = normalizeString($userSummary['firstname'] ?? null, 255);
            }

            $lastname = normalizeString($userDetails['lastname'] ?? null, 255);
            if ($lastname === null) {
                $lastname = normalizeString($userSummary['lastname'] ?? null, 255);
            }

            $mail = normalizeString($userDetails['mail'] ?? null, 255);
            if ($mail === null) {
                $mail = normalizeString($userSummary['mail'] ?? null, 255);
            }
            if ($mail === null) {
                throw new RuntimeException(sprintf('Redmine user %d is missing an email address. Ensure the API key belongs to an administrator.', $userId));
            }

            $statusValue = $userDetails['status'] ?? null;
            if (!is_int($statusValue) && !(is_string($statusValue) && is_numeric($statusValue))) {
                throw new RuntimeException(sprintf('Redmine user %d payload does not include a status value.', $userId));
            }
            $status = (int)$statusValue;

            try {
                $rawPayload = json_encode($userDetails, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (JsonException $exception) {
                throw new RuntimeException(sprintf('Failed to encode Redmine user payload for user %d: %s', $userId, $exception->getMessage()), 0, $exception);
            }

            $rowsToInsert[] = [
                'id' => $userId,
                'login' => $login,
                'firstname' => $firstname,
                'lastname' => $lastname,
                'mail' => $mail,
                'status' => $status,
                'raw_payload' => $rawPayload,
                'retrieved_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            ];
        }

        if ($rowsToInsert === []) {
            $offset += $limit;
            continue;
        }

        $pdo->beginTransaction();

        try {
            foreach ($rowsToInsert as $row) {
                $insertStatement->execute($row);
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        $totalProcessed += count($rowsToInsert);

        $responseOffset = isset($decoded['offset']) && is_numeric($decoded['offset']) ? (int)$decoded['offset'] : $offset;
        $responseLimit = isset($decoded['limit']) && is_numeric($decoded['limit']) ? (int)$decoded['limit'] : $limit;
        if ($responseLimit <= 0) {
            $responseLimit = $limit;
        }
        $offset = $responseOffset + $responseLimit;

        printf("Processed %d Redmine users (total: %d).%s", count($rowsToInsert), $totalProcessed, PHP_EOL);

        $totalCount = isset($decoded['total_count']) && is_numeric($decoded['total_count']) ? (int)$decoded['total_count'] : null;
        if ($totalCount !== null && $offset >= $totalCount) {
            break;
        }

        if ($batchCount < $limit) {
            break;
        }
    }

    return $totalProcessed;
}

/**
 * @return array<string, mixed>
 */
function fetchRedmineUserDetail(Client $client, int $userId): array
{
    try {
        $response = $client->get(sprintf('users/%d.json', $userId), [
            'query' => [
                'include' => 'groups,memberships',
            ],
        ]);
    } catch (GuzzleException $exception) {
        throw new RuntimeException(sprintf('Failed to fetch Redmine user %d: %s', $userId, $exception->getMessage()), 0, $exception);
    }

    try {
        $decoded = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException(sprintf('Unable to decode Redmine user %d payload: %s', $userId, $exception->getMessage()), 0, $exception);
    }

    if (!is_array($decoded) || !isset($decoded['user']) || !is_array($decoded['user'])) {
        throw new RuntimeException(sprintf('Unexpected structure when retrieving Redmine user %d.', $userId));
    }

    return $decoded['user'];
}

function normalizeString(mixed $value, int $maxLength): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    if (function_exists('mb_substr')) {
        return mb_substr($trimmed, 0, $maxLength);
    }

    return substr($trimmed, 0, $maxLength);
}

// Further transformation and load steps will be implemented in subsequent iterations of this script.
