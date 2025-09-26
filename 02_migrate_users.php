<?php
/** @noinspection DuplicatedCode */
/** @noinspection SpellCheckingInspection */

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

const MIGRATE_USERS_SCRIPT_VERSION = '0.0.4';
const AVAILABLE_PHASES = [
    'jira' => 'Extract users from Jira and persist them into staging_jira_users.',
    'redmine' => 'Refresh the staging_redmine_users snapshot from the Redmine REST API.',
    'transform' => 'Reconcile Jira and Redmine data to populate migration mappings.',
    'push' => 'Create migration-ready users in Redmine via the REST API.',
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
 * @param array{help: bool, version: bool, phases: ?string, skip: ?string, confirm_push: bool, dry_run: bool} $cliOptions
 * @throws Throwable
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
        formatCurrentTimestamp(),
        implode(', ', $phaseSummary),
        PHP_EOL
    );

    $databaseConfig = extractArrayConfig($config, 'database');
    $pdo = createDatabaseConnection($databaseConfig);

    if (in_array('jira', $phasesToRun, true)) {
        $jiraConfig = extractArrayConfig($config, 'jira');
        $jiraClient = createJiraClient($jiraConfig);

        printf("[%s] Starting Jira user extraction...%s", formatCurrentTimestamp(), PHP_EOL);

        $totalJiraProcessed = fetchAndStoreJiraUsers($jiraClient, $pdo);

        printf(
            "[%s] Completed Jira extraction. %d user records processed.%s",
            formatCurrentTimestamp(),
            $totalJiraProcessed,
            PHP_EOL
        );
    } else {
        printf(
            "[%s] Skipping Jira user extraction (disabled via CLI option).%s",
            formatCurrentTimestamp(),
            PHP_EOL
        );
    }

    if (in_array('redmine', $phasesToRun, true)) {
        $redmineConfig = extractArrayConfig($config, 'redmine');
        $redmineClient = createRedmineClient($redmineConfig);

        printf("[%s] Starting Redmine user snapshot...%s", formatCurrentTimestamp(), PHP_EOL);

        $totalRedmineProcessed = fetchAndStoreRedmineUsers($redmineClient, $pdo);

        printf(
            "[%s] Completed Redmine snapshot. %d user records processed.%s",
            formatCurrentTimestamp(),
            $totalRedmineProcessed,
            PHP_EOL
        );
    } else {
        printf(
            "[%s] Skipping Redmine user snapshot (disabled via CLI option).%s",
            formatCurrentTimestamp(),
            PHP_EOL
        );
    }

    if (in_array('transform', $phasesToRun, true)) {
        printf(
            "[%s] Starting user reconciliation & transform phase...%s",
            formatCurrentTimestamp(),
            PHP_EOL
        );

        $defaultRedmineUserStatus = determineDefaultRedmineUserStatus($config);

        $transformSummary = runUserTransformationPhase($pdo, $defaultRedmineUserStatus);

        printf(
            "[%s] Completed transform phase. Matched: %d, Ready: %d, Manual: %d, Overrides kept: %d, Skipped: %d, Unchanged: %d.%s",
            formatCurrentTimestamp(),
            $transformSummary['matched'],
            $transformSummary['ready_for_creation'],
            $transformSummary['manual_review'],
            $transformSummary['manual_overrides'],
            $transformSummary['skipped'],
            $transformSummary['unchanged'],
            PHP_EOL
        );

        if ($transformSummary['status_counts'] !== []) {
            printf("Current migration status breakdown:%s", PHP_EOL);
            foreach ($transformSummary['status_counts'] as $status => $count) {
                printf("  - %-28s %d%s", $status, $count, PHP_EOL);
            }
        }
    } else {
        printf(
            "[%s] Skipping transform phase (disabled via CLI option).%s",
            formatCurrentTimestamp(),
            PHP_EOL
        );
    }

    if (in_array('push', $phasesToRun, true)) {
        $confirmPush = (bool)($cliOptions['confirm_push'] ?? false);
        $isDryRun = (bool)($cliOptions['dry_run'] ?? false);

        runPushPhase($pdo, $config, $confirmPush, $isDryRun);
    } else {
        printf(
            "[%s] Skipping push phase (disabled via CLI option).%s",
            formatCurrentTimestamp(),
            PHP_EOL
        );
    }
}

/**
 * @param PDO $pdo
 * @param array<string, mixed> $config
 * @param bool $confirmPush
 * @param bool $isDryRun
 */
function runPushPhase(PDO $pdo, array $config, bool $confirmPush, bool $isDryRun): void
{
    printf("[%s] Starting push phase (load)...%s", formatCurrentTimestamp(), PHP_EOL);

    $pendingOperations = fetchPendingPushOperations($pdo);
    $pendingCount = count($pendingOperations);

    if ($pendingCount === 0) {
        printf("  No user accounts are marked as READY_FOR_CREATION in migration_mapping_users.%s", PHP_EOL);

        if ($isDryRun) {
            printf("  --dry-run flag enabled: Redmine will not be modified.%s", PHP_EOL);
        }

        if ($confirmPush) {
            printf("  No pending operations: Redmine API was not contacted.%s", PHP_EOL);
        } else {
            printf("  --confirm-push flag missing: no Redmine API calls were attempted.%s", PHP_EOL);
        }

        printf("[%s] Push phase finished without contacting Redmine.%s", formatCurrentTimestamp(), PHP_EOL);

        return;
    }

    printf("  %d user account(s) are marked as READY_FOR_CREATION in migration_mapping_users.%s", $pendingCount, PHP_EOL);

    if ($isDryRun) {
        printf("  Dry-run preview of queued Redmine creations:%s", PHP_EOL);
        outputPushPreview($pendingOperations);
        printf("  --dry-run flag enabled: Redmine will not be modified.%s", PHP_EOL);
        printf("[%s] Push phase finished without contacting Redmine.%s", formatCurrentTimestamp(), PHP_EOL);

        return;
    }

    if (!$confirmPush) {
        printf("  Re-run with --dry-run to preview the exact payloads before enabling the push.%s", PHP_EOL);
        printf("  --confirm-push flag missing: no Redmine API calls were attempted.%s", PHP_EOL);
        printf("[%s] Push phase finished without contacting Redmine.%s", formatCurrentTimestamp(), PHP_EOL);

        return;
    }

    printf("  Push confirmation supplied; creating Redmine users...%s", PHP_EOL);

    $redmineClient = createRedmineClient(extractArrayConfig($config, 'redmine'));
    $defaultStatus = determineDefaultRedmineUserStatus($config);
    $authSourceId = determineRedmineUserAuthSourceId($config);

    [$successCount, $failureCount] = executeRedmineUserPush(
        $pdo,
        $redmineClient,
        $pendingOperations,
        $defaultStatus,
        $authSourceId
    );

    printf("  Push summary: %d succeeded, %d failed.%s", $successCount, $failureCount, PHP_EOL);
    printf("[%s] Push phase finished with Redmine API interactions.%s", formatCurrentTimestamp(), PHP_EOL);
}

/**
 * @param PDO $pdo
 * @return array<int, array{
 *     mapping_id: int,
 *     jira_account_id: string,
 *     proposed_redmine_login: ?string,
 *     proposed_redmine_mail: ?string,
 *     proposed_firstname: ?string,
 *     proposed_lastname: ?string,
 *     proposed_redmine_status: ?string
 * }>
 */
function fetchPendingPushOperations(PDO $pdo): array
{
    $sql = <<<SQL
SELECT
    mapping_id,
    jira_account_id,
    proposed_redmine_login,
    proposed_redmine_mail,
    proposed_firstname,
    proposed_lastname,
    proposed_redmine_status
FROM migration_mapping_users
WHERE migration_status = 'READY_FOR_CREATION'
ORDER BY mapping_id
SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to inspect pending push operations: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    /** @var array<int, array<string, mixed>> $rows */
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

    $records = [];
    foreach ($rows as $row) {
        $records[] = [
            'mapping_id' => (int)$row['mapping_id'],
            'jira_account_id' => (string)$row['jira_account_id'],
            'proposed_redmine_login' => $row['proposed_redmine_login'] !== null ? (string)$row['proposed_redmine_login'] : null,
            'proposed_redmine_mail' => $row['proposed_redmine_mail'] !== null ? (string)$row['proposed_redmine_mail'] : null,
            'proposed_firstname' => $row['proposed_firstname'] !== null ? (string)$row['proposed_firstname'] : null,
            'proposed_lastname' => $row['proposed_lastname'] !== null ? (string)$row['proposed_lastname'] : null,
            'proposed_redmine_status' => $row['proposed_redmine_status'] !== null ? (string)$row['proposed_redmine_status'] : null,
        ];
    }

    return $records;
}

/**
 * @param array<int, array{
 *     mapping_id: int,
 *     jira_account_id: string,
 *     proposed_redmine_login: ?string,
 *     proposed_redmine_mail: ?string,
 *     proposed_firstname: ?string,
 *     proposed_lastname: ?string,
 *     proposed_redmine_status: ?string
 * }> $pendingOperations
 */
function outputPushPreview(array $pendingOperations): void
{
    foreach ($pendingOperations as $operation) {
        printf(
            "    - [mapping #%d] Jira %s => login=%s, mail=%s, firstname=%s, lastname=%s, status=%s%s",
            $operation['mapping_id'],
            $operation['jira_account_id'],
            formatPushPreviewField($operation['proposed_redmine_login']),
            formatPushPreviewField($operation['proposed_redmine_mail']),
            formatPushPreviewField($operation['proposed_firstname']),
            formatPushPreviewField($operation['proposed_lastname']),
            formatPushPreviewField($operation['proposed_redmine_status']),
            PHP_EOL
        );
    }
}

function formatPushPreviewField(?string $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return '"' . addcslashes($value, "\"\\") . '"';
    }

    return $encoded;
}

/**
 * @param PDO $pdo
 * @param Client $redmineClient
 * @param array<int, array{
 *     mapping_id: int,
 *     jira_account_id: string,
 *     proposed_redmine_login: ?string,
 *     proposed_redmine_mail: ?string,
 *     proposed_firstname: ?string,
 *     proposed_lastname: ?string,
 *     proposed_redmine_status: ?string
 * }> $pendingOperations
 * @param string $defaultStatus
 * @param int|null $authSourceId
 * @return array{0: int, 1: int}
 */
function executeRedmineUserPush(
    PDO $pdo,
    Client $redmineClient,
    array $pendingOperations,
    string $defaultStatus,
    ?int $authSourceId
): array
{
    $updateStatement = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_users
        SET
            redmine_user_id = :redmine_user_id,
            migration_status = :migration_status,
            match_type = :match_type,
            proposed_redmine_login = :proposed_redmine_login,
            proposed_redmine_mail = :proposed_redmine_mail,
            proposed_firstname = :proposed_firstname,
            proposed_lastname = :proposed_lastname,
            proposed_redmine_status = :proposed_redmine_status,
            notes = :notes,
            automation_hash = :automation_hash
        WHERE mapping_id = :mapping_id
    SQL);

    if ($updateStatement === false) {
        throw new RuntimeException('Failed to prepare update statement for migration_mapping_users during the push phase.');
    }

    $successCount = 0;
    $failureCount = 0;

    foreach ($pendingOperations as $operation) {
        $preparedFields = null;

        try {
            $preparedFields = prepareRedmineUserCreationFields($operation, $defaultStatus);
            $newUserId = sendRedmineUserCreationRequest($redmineClient, $preparedFields, $authSourceId);
        } catch (Throwable $exception) {
            $errorMessage = $exception->getMessage();
            $updateValues = buildPushUpdateValues(
                $operation,
                $preparedFields,
                $defaultStatus,
                'CREATION_FAILED',
                null,
                $errorMessage
            );

            try {
                $updateStatement->execute($updateValues);
            } catch (Throwable $updateException) {
                throw new RuntimeException(
                    sprintf(
                        'Failed to record push failure for mapping %d: %s',
                        $operation['mapping_id'],
                        $updateException->getMessage()
                    ),
                    0,
                    $updateException
                );
            }

            printf(
                "    [failed] Jira %s (mapping #%d): %s%s",
                $operation['jira_account_id'],
                $operation['mapping_id'],
                $errorMessage,
                PHP_EOL
            );

            $failureCount++;
            continue;
        }

        $updateValues = buildPushUpdateValues(
            $operation,
            $preparedFields,
            $defaultStatus,
            'CREATION_SUCCESS',
            $newUserId,
            null
        );

        $updateStatement->execute($updateValues);

        printf(
            "    [created] Jira %s -> Redmine #%d (mapping #%d)%s",
            $operation['jira_account_id'],
            $newUserId,
            $operation['mapping_id'],
            PHP_EOL
        );

        $successCount++;
    }

    return [$successCount, $failureCount];
}

/**
 * @param array{
 *     mapping_id: int,
 *     jira_account_id: string,
 *     proposed_redmine_login: ?string,
 *     proposed_redmine_mail: ?string,
 *     proposed_firstname: ?string,
 *     proposed_lastname: ?string,
 *     proposed_redmine_status: ?string
 * } $operation
 * @return array{login: string, mail: string, firstname: string, lastname: string, status_label: string, status_code: int}
 */
function prepareRedmineUserCreationFields(array $operation, string $defaultStatus): array
{
    $login = requireNonEmptyPushField($operation, $operation['proposed_redmine_login'] ?? null, 'Proposed Redmine login', 255);
    $mail = requireNonEmptyPushField($operation, $operation['proposed_redmine_mail'] ?? null, 'Proposed Redmine email', 255);
    $firstname = requireNonEmptyPushField($operation, $operation['proposed_firstname'] ?? null, 'Proposed Redmine firstname', 255);
    $lastname = requireNonEmptyPushField($operation, $operation['proposed_lastname'] ?? null, 'Proposed Redmine lastname', 255);

    $statusLabel = normalizeProposedRedmineStatus($operation['proposed_redmine_status'] ?? null, $defaultStatus);
    $statusCode = mapRedmineStatusLabelToCode($statusLabel);

    return [
        'login' => $login,
        'mail' => $mail,
        'firstname' => $firstname,
        'lastname' => $lastname,
        'status_label' => $statusLabel,
        'status_code' => $statusCode,
    ];
}

/**
 * @param array{
 *     mapping_id: int,
 *     jira_account_id: string
 * } $operation
 */
function requireNonEmptyPushField(array $operation, ?string $value, string $fieldLabel, int $maxLength): string
{
    $normalized = normalizeString($value, $maxLength);
    if ($normalized === null) {
        throw new RuntimeException(sprintf(
            '%s is missing for Jira account %s (mapping #%d).',
            $fieldLabel,
            $operation['jira_account_id'],
            $operation['mapping_id']
        ));
    }

    return $normalized;
}

/**
 * @param Client $client
 * @param array{login: string, mail: string, firstname: string, lastname: string, status_label: string, status_code: int} $fields
 * @param int|null $authSourceId
 * @return int
 */
function sendRedmineUserCreationRequest(Client $client, array $fields, ?int $authSourceId): int
{
    $payload = [
        'user' => [
            'login' => $fields['login'],
            'firstname' => $fields['firstname'],
            'lastname' => $fields['lastname'],
            'mail' => $fields['mail'],
            'generate_password' => true,
            'must_change_passwd' => true,
            'status' => $fields['status_code'],
        ],
    ];

    if ($authSourceId !== null) {
        $payload['user']['auth_source_id'] = $authSourceId;
    }

    try {
        $response = $client->post('users.json', ['json' => $payload]);
    } catch (BadResponseException $exception) {
        $message = extractRedmineErrorMessage($exception->getResponse(), $exception->getMessage());
        throw new RuntimeException($message, 0, $exception);
    } catch (GuzzleException $exception) {
        throw new RuntimeException('Redmine user creation request failed: ' . $exception->getMessage(), 0, $exception);
    }

    $statusCode = $response->getStatusCode();
    if ($statusCode !== 201 && $statusCode !== 200) {
        $message = extractRedmineErrorMessage(
            $response,
            sprintf('Unexpected HTTP status %d when creating a Redmine user.', $statusCode)
        );

        throw new RuntimeException($message);
    }

    try {
        $decoded = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Unable to decode Redmine user creation response: ' . $exception->getMessage(), 0, $exception);
    }

    if (!is_array($decoded) || !isset($decoded['user']) || !is_array($decoded['user'])) {
        throw new RuntimeException('Unexpected structure in Redmine user creation response.');
    }

    $user = $decoded['user'];
    if (!isset($user['id']) || !is_numeric($user['id'])) {
        throw new RuntimeException('Redmine user creation response did not include a user ID.');
    }

    return (int)$user['id'];
}
function mapRedmineStatusLabelToCode(string $statusLabel): int
{
    if ($statusLabel === 'ACTIVE') {
        return 1;
    }

    return 3;
}

/**
 * @param array{
 *     mapping_id: int,
 *     jira_account_id: string,
 *     proposed_redmine_login: ?string,
 *     proposed_redmine_mail: ?string,
 *     proposed_firstname: ?string,
 *     proposed_lastname: ?string,
 *     proposed_redmine_status: ?string
 * } $operation
 * @param array{login: string, mail: string, firstname: string, lastname: string, status_label: string, status_code: int}|null $preparedFields
 * @return array{
 *     mapping_id: int,
 *     redmine_user_id: ?int,
 *     migration_status: string,
 *     match_type: string,
 *     proposed_redmine_login: ?string,
 *     proposed_redmine_mail: ?string,
 *     proposed_firstname: ?string,
 *     proposed_lastname: ?string,
 *     proposed_redmine_status: string,
 *     notes: ?string,
 *     automation_hash: string
 * }
 */
function buildPushUpdateValues(
    array $operation,
    ?array $preparedFields,
    string $defaultStatus,
    string $migrationStatus,
    ?int $redmineUserId,
    ?string $notes
): array {
    $login = $preparedFields['login'] ?? ($operation['proposed_redmine_login'] ?? null);
    $mail = $preparedFields['mail'] ?? ($operation['proposed_redmine_mail'] ?? null);
    $firstname = $preparedFields['firstname'] ?? ($operation['proposed_firstname'] ?? null);
    $lastname = $preparedFields['lastname'] ?? ($operation['proposed_lastname'] ?? null);
    $statusLabel = $preparedFields['status_label'] ?? normalizeProposedRedmineStatus(
        $operation['proposed_redmine_status'] ?? null,
        $defaultStatus
    );

    $matchType = 'NONE';

    $automationHash = computeAutomationStateHash(
        $redmineUserId,
        $migrationStatus,
        $matchType,
        $login,
        $mail,
        $firstname,
        $lastname,
        $statusLabel,
        $notes
    );

    return [
        'mapping_id' => $operation['mapping_id'],
        'redmine_user_id' => $redmineUserId,
        'migration_status' => $migrationStatus,
        'match_type' => $matchType,
        'proposed_redmine_login' => $login,
        'proposed_redmine_mail' => $mail,
        'proposed_firstname' => $firstname,
        'proposed_lastname' => $lastname,
        'proposed_redmine_status' => $statusLabel,
        'notes' => $notes,
        'automation_hash' => $automationHash,
    ];
}

function extractRedmineErrorMessage(?ResponseInterface $response, string $fallback): string
{
    $fallback = trim($fallback);
    if ($response === null) {
        return $fallback !== '' ? $fallback : 'Unknown error when contacting Redmine.';
    }

    $statusCode = $response->getStatusCode();
    $message = null;
    $body = (string)$response->getBody();

    if ($body !== '') {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = null;
        }

        if (is_array($decoded)) {
            if (isset($decoded['errors']) && is_array($decoded['errors'])) {
                $parts = [];
                foreach ($decoded['errors'] as $error) {
                    if (!is_string($error)) {
                        continue;
                    }

                    $trimmed = trim($error);
                    if ($trimmed !== '') {
                        $parts[] = $trimmed;
                    }
                }

                if ($parts !== []) {
                    $message = implode('; ', $parts);
                }
            } elseif (isset($decoded['error']) && is_string($decoded['error'])) {
                $candidate = trim($decoded['error']);
                if ($candidate !== '') {
                    $message = $candidate;
                }
            }
        }

        if ($message === null) {
            $stripped = trim(strip_tags($body));
            if ($stripped !== '') {
                if (function_exists('mb_substr')) {
                    $message = mb_substr($stripped, 0, 500);
                } else {
                    $message = substr($stripped, 0, 500);
                }
            }
        }
    }

    if ($message === null || $message === '') {
        $message = $fallback !== '' ? $fallback : 'Unknown error response received from Redmine.';
    }

    if (function_exists('mb_strlen')) {
        if (mb_strlen($message) > 500) {
            $message = mb_substr($message, 0, 500) . '…';
        }
    } elseif (strlen($message) > 500) {
        $message = substr($message, 0, 500) . '…';
    }

    return sprintf('HTTP %d: %s', $statusCode, $message);
}

/**
 * @param array{help: bool, version: bool, phases: ?string, skip: ?string, confirm_push: bool, dry_run: bool} $cliOptions
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
 * @return array{0: array{help: bool, version: bool, phases: ?string, skip: ?string, confirm_push: bool, dry_run: bool}, 1: array<int, string>}
 */
function parseCommandLineOptions(array $argv): array
{
    $options = [
        'help' => false,
        'version' => false,
        'phases' => null,
        'skip' => null,
        'confirm_push' => false,
        'dry_run' => false,
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

        if ($argument === '--confirm-push') {
            $options['confirm_push'] = true;
            continue;
        }

        if ($argument === '--dry-run') {
            $options['dry_run'] = true;
            continue;
        }

        if ($argument === '--phases') {
            $index++;
            if ($index >= $argumentCount) {
                throw new RuntimeException('The --phases option requires a value.');
            }

            $phaseValue = $argv[$index];
            if (!is_string($phaseValue)) {
                throw new RuntimeException('The --phases option expects string arguments.');
            }

            $options['phases'] = trim($phaseValue);
            continue;
        }

        if (str_starts_with($argument, '--phases=')) {
            $options['phases'] = trim(substr($argument, 9));
            continue;
        }

        if ($argument === '--skip') {
            $index++;
            if ($index >= $argumentCount) {
                throw new RuntimeException('The --skip option requires a value.');
            }

            $skipValue = $argv[$index];
            if (!is_string($skipValue)) {
                throw new RuntimeException('The --skip option expects string arguments.');
            }

            $options['skip'] = trim($skipValue);
            continue;
        }

        if (str_starts_with($argument, '--skip=')) {
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
    echo "      --confirm-push   Allow the push phase to contact Redmine (required for future writes)." . PHP_EOL;
    echo "      --dry-run        Preview push-phase actions without contacting Redmine." . PHP_EOL;
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
    echo sprintf('  php %s --phases=push --dry-run%s', $scriptName, PHP_EOL);
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
 * @param array<string, mixed> $config
 */
function determineDefaultRedmineUserStatus(array $config): string
{
    $defaultStatus = 'LOCKED';

    if (!array_key_exists('migration', $config) || !is_array($config['migration'])) {
        return $defaultStatus;
    }

    $migrationConfig = $config['migration'];
    if (!array_key_exists('users', $migrationConfig) || !is_array($migrationConfig['users'])) {
        return $defaultStatus;
    }

    $rawValue = $migrationConfig['users']['default_redmine_user_status'] ?? null;
    if (is_string($rawValue)) {
        $normalized = strtoupper(trim($rawValue));
        if ($normalized === 'ACTIVE' || $normalized === 'LOCKED') {
            return $normalized;
        }
    }

    return $defaultStatus;
}

/**
 * @param array<string, mixed> $config
 */
function determineRedmineUserAuthSourceId(array $config): ?int
{
    if (!array_key_exists('migration', $config) || !is_array($config['migration'])) {
        return null;
    }

    $migrationConfig = $config['migration'];
    if (!array_key_exists('users', $migrationConfig) || !is_array($migrationConfig['users'])) {
        return null;
    }

    $rawValue = $migrationConfig['users']['auth_source_id'] ?? null;

    if ($rawValue === null) {
        return null;
    }

    if (is_int($rawValue)) {
        return $rawValue > 0 ? $rawValue : null;
    }

    if (is_string($rawValue)) {
        $normalized = trim($rawValue);
        if ($normalized === '') {
            return null;
        }

        if (ctype_digit($normalized)) {
            $value = (int)$normalized;

            return $value > 0 ? $value : null;
        }
    }

    throw new RuntimeException('Invalid configuration for migration.users.auth_source_id; expected a positive integer or null.');
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

function formatCurrentTimestamp(?string $format = null): string
{
    $format ??= DateTimeInterface::ATOM;

    return date($format);
}

function formatCurrentUtcTimestamp(string $format): string
{
    return gmdate($format);
}

/**
 * @param string $defaultRedmineUserStatus Proposed default status for new Redmine accounts.
 * @return array{matched: int, ready_for_creation: int, manual_review: int, manual_overrides: int, skipped: int, unchanged: int, status_counts: array<string, int>}
 */
function runUserTransformationPhase(PDO $pdo, string $defaultRedmineUserStatus): array
{
    synchronizeMigrationMappingUsers($pdo);

    [, $redmineByLogin, $redmineByMail] = fetchRedmineUserLookups($pdo);
    $mappings = fetchUserMappingsForTransform($pdo);

    $updateStatement = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_users
        SET
            redmine_user_id = :redmine_user_id,
            migration_status = :migration_status,
            match_type = :match_type,
            proposed_redmine_login = :proposed_redmine_login,
            proposed_redmine_mail = :proposed_redmine_mail,
            proposed_firstname = :proposed_firstname,
            proposed_lastname = :proposed_lastname,
            proposed_redmine_status = :proposed_redmine_status,
            notes = :notes,
            automation_hash = :automation_hash
        WHERE mapping_id = :mapping_id
    SQL);
    if ($updateStatement === false) {
        throw new RuntimeException('Failed to prepare update statement for migration_mapping_users.');
    }

    $allowedStatuses = ['PENDING_ANALYSIS', 'READY_FOR_CREATION', 'MATCH_FOUND'];

    $summary = [
        'matched' => 0,
        'ready_for_creation' => 0,
        'manual_review' => 0,
        'manual_overrides' => 0,
        'skipped' => 0,
        'unchanged' => 0,
    ];

    foreach ($mappings as $row) {
        $currentStatus = (string)$row['migration_status'];
        if (!in_array($currentStatus, $allowedStatuses, true)) {
            $summary['skipped']++;
            continue;
        }

        $matchTypeValue = $row['match_type'] ?? null;
        $currentMatchType = $matchTypeValue !== null
            ? (string)$matchTypeValue
            : 'NONE';
        if ($currentMatchType === 'MANUAL') {
            $summary['skipped']++;
            continue;
        }

        $jiraAccountId = (string)$row['jira_account_id'];
        $hasStagingData = $row['staging_account_id'] !== null;

        $jiraDisplayNameRaw = $row['staging_display_name'] ?? $row['jira_display_name'] ?? null;
        $jiraDisplayName = normalizeString($jiraDisplayNameRaw, 255);

        $jiraEmailRaw = $row['staging_email_address'] ?? $row['jira_email_address'] ?? null;
        $jiraEmail = normalizeString($jiraEmailRaw, 255);

        $manualReason = null;
        $matchedUser = null;
        $newMatchType = 'NONE';

        if (!$hasStagingData) {
            $manualReason = 'No staging data available for this Jira account. Re-run the extraction phase.';
        } elseif ($jiraEmail === null) {
            $manualReason = 'Missing Jira email address; unable to auto-match or propose a Redmine login.';
        } else {
            $emailKey = lowercaseValue($jiraEmail);
            if ($emailKey !== null) {
                $loginMatches = $redmineByLogin[$emailKey] ?? [];
                if (count($loginMatches) === 1) {
                    $matchedUser = $loginMatches[0];
                    $newMatchType = 'LOGIN';
                } elseif (count($loginMatches) > 1) {
                    $manualReason = sprintf('Multiple Redmine accounts share the login "%s".', $jiraEmail);
                }

                if ($matchedUser === null && $manualReason === null) {
                    $mailMatches = $redmineByMail[$emailKey] ?? [];
                    if (count($mailMatches) === 1) {
                        $matchedUser = $mailMatches[0];
                        $newMatchType = 'MAIL';
                    } elseif (count($mailMatches) > 1) {
                        $manualReason = sprintf('Multiple Redmine accounts share the email "%s".', $jiraEmail);
                    }
                }
            }
        }

        $currentRedmineId = $row['redmine_user_id'] !== null ? (int)$row['redmine_user_id'] : null;
        $proposedLogin = $row['proposed_redmine_login'] ?? null;
        $proposedMail = $row['proposed_redmine_mail'] ?? null;
        $proposedFirstname = $row['proposed_firstname'] ?? null;
        $proposedLastname = $row['proposed_lastname'] ?? null;
        $proposedStatus = normalizeProposedRedmineStatus($row['proposed_redmine_status'] ?? null, $defaultRedmineUserStatus);
        $currentNotes = $row['notes'] ?? null;

        $storedAutomationHash = normalizeStoredAutomationHash($row['automation_hash'] ?? null);
        $currentAutomationHash = computeAutomationStateHash(
            $currentRedmineId,
            $currentStatus,
            $currentMatchType,
            $proposedLogin,
            $proposedMail,
            $proposedFirstname,
            $proposedLastname,
            $proposedStatus,
            $currentNotes
        );
        if ($storedAutomationHash !== null && $storedAutomationHash !== $currentAutomationHash) {
            $summary['manual_overrides']++;
            printf(
                "  [preserved] Jira account %s has manual overrides; skipping automated changes.%s",
                $jiraAccountId,
                PHP_EOL
            );
            continue;
        }

        if ($manualReason !== null) {
            $newStatus = 'MANUAL_INTERVENTION_REQUIRED';
            $newMatchType = 'NONE';
            $newRedmineId = null;
            $proposedLogin = $jiraEmail;
            $proposedMail = $jiraEmail;
            [$derivedFirst, $derivedLast] = deriveNamePartsFromDisplayName($jiraDisplayName);
            $proposedFirstname = $derivedFirst;
            $proposedLastname = $derivedLast;
            $proposedStatus = $defaultRedmineUserStatus;
            $notes = $manualReason;

            printf("  [manual] Jira account %s: %s%s", $jiraAccountId, $manualReason, PHP_EOL);
        } elseif ($matchedUser !== null) {
            $newStatus = 'MATCH_FOUND';
            $newRedmineId = (int)$matchedUser['id'];
            $proposedLogin = normalizeString($matchedUser['login'], 255);
            $proposedMail = normalizeString($matchedUser['mail'], 255);
            $proposedFirstname = normalizeString($matchedUser['firstname'] ?? null, 255);
            $proposedLastname = normalizeString($matchedUser['lastname'] ?? null, 255);
            $proposedStatus = deriveRedmineStatusLabelFromSnapshot($matchedUser);
            if ($proposedFirstname === null || $proposedLastname === null) {
                [$derivedFirst, $derivedLast] = deriveNamePartsFromDisplayName($jiraDisplayName);
                if ($proposedFirstname === null) {
                    $proposedFirstname = $derivedFirst;
                }
                if ($proposedLastname === null) {
                    $proposedLastname = $derivedLast;
                }
            }
            $notes = null;
        } else {
            [$derivedFirst, $derivedLast] = deriveNamePartsFromDisplayName($jiraDisplayName);
            $proposedFirstname = $derivedFirst;
            $proposedLastname = $derivedLast;
            $proposedLogin = $jiraEmail;
            $proposedMail = $jiraEmail;
            $newRedmineId = null;
            $newMatchType = 'NONE';
            $proposedStatus = $defaultRedmineUserStatus;

            if ($jiraEmail === null) {
                $newStatus = 'MANUAL_INTERVENTION_REQUIRED';
                $notes = 'Missing Jira email address; unable to create a Redmine user automatically.';
                printf("  [manual] Jira account %s: %s%s", $jiraAccountId, $notes, PHP_EOL);
            } elseif ($derivedFirst === null || $derivedLast === null) {
                $newStatus = 'MANUAL_INTERVENTION_REQUIRED';
                $displayNameForNotes = $jiraDisplayName ?? '[unknown]';
                $notes = sprintf('Unable to derive firstname/lastname from Jira display name "%s".', $displayNameForNotes);
                printf("  [manual] Jira account %s: %s%s", $jiraAccountId, $notes, PHP_EOL);
            } else {
                $newStatus = 'READY_FOR_CREATION';
                $notes = null;
            }
        }

        if ($newStatus !== 'MANUAL_INTERVENTION_REQUIRED') {
            $notes = null;
        }

        $newAutomationHash = computeAutomationStateHash(
            $newRedmineId,
            $newStatus,
            $newMatchType,
            $proposedLogin,
            $proposedMail,
            $proposedFirstname,
            $proposedLastname,
            $proposedStatus,
            $notes
        );

        $needsUpdate = $currentRedmineId !== $newRedmineId
            || $currentStatus !== $newStatus
            || $currentMatchType !== $newMatchType
            || (($row['proposed_redmine_login'] ?? null) !== $proposedLogin)
            || (($row['proposed_redmine_mail'] ?? null) !== $proposedMail)
            || (($row['proposed_firstname'] ?? null) !== $proposedFirstname)
            || (($row['proposed_lastname'] ?? null) !== $proposedLastname)
            || (normalizeProposedRedmineStatus($row['proposed_redmine_status'] ?? null, $defaultRedmineUserStatus) !== $proposedStatus)
            || (($row['notes'] ?? null) !== $notes)
            || (($row['automation_hash'] ?? null) !== $newAutomationHash);

        if (!$needsUpdate) {
            $summary['unchanged']++;
            continue;
        }

        $updateStatement->execute([
            'redmine_user_id' => $newRedmineId,
            'migration_status' => $newStatus,
            'match_type' => $newMatchType,
            'proposed_redmine_login' => $proposedLogin,
            'proposed_redmine_mail' => $proposedMail,
            'proposed_firstname' => $proposedFirstname,
            'proposed_lastname' => $proposedLastname,
            'proposed_redmine_status' => $proposedStatus,
            'notes' => $notes,
            'automation_hash' => $newAutomationHash,
            'mapping_id' => $row['mapping_id'],
        ]);

        if ($newStatus === 'MATCH_FOUND' && $newStatus !== $currentStatus) {
            $summary['matched']++;
        } elseif ($newStatus === 'READY_FOR_CREATION' && $newStatus !== $currentStatus) {
            $summary['ready_for_creation']++;
        } elseif ($newStatus === 'MANUAL_INTERVENTION_REQUIRED' && $newStatus !== $currentStatus) {
            $summary['manual_review']++;
        }
    }

    $summary['status_counts'] = fetchMigrationStatusCounts($pdo);

    return $summary;
}

function synchronizeMigrationMappingUsers(PDO $pdo): void
{
    $sql = <<<'SQL'
        INSERT INTO migration_mapping_users (jira_account_id, jira_display_name, jira_email_address)
        SELECT account_id, display_name, email_address
        FROM staging_jira_users
        ON DUPLICATE KEY UPDATE
            jira_display_name = VALUES(jira_display_name),
            jira_email_address = VALUES(jira_email_address)
    SQL;

    $result = $pdo->exec($sql);
    if ($result === false) {
        throw new RuntimeException('Failed to synchronize Jira users into migration_mapping_users.');
    }
}

/**
 * @return array{0: array<int, array<string, mixed>>, 1: array<string, array<int, array<string, mixed>>>, 2: array<string, array<int, array<string, mixed>>>}
 */
function fetchRedmineUserLookups(PDO $pdo): array
{
    $statement = $pdo->query('SELECT id, login, mail, firstname, lastname, status FROM staging_redmine_users');
    if ($statement === false) {
        throw new RuntimeException('Failed to fetch Redmine user snapshot from the database.');
    }

    $byId = [];
    $byLogin = [];
    $byMail = [];

    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!isset($row['id'])) {
            continue;
        }

        $id = (int)$row['id'];
        $byId[$id] = $row;

        if (isset($row['login'])) {
            $loginKey = lowercaseValue($row['login']);
            if ($loginKey !== null) {
                $byLogin[$loginKey][] = $row;
            }
        }

        if (isset($row['mail'])) {
            $mailKey = lowercaseValue($row['mail']);
            if ($mailKey !== null) {
                $byMail[$mailKey][] = $row;
            }
        }
    }

    return [$byId, $byLogin, $byMail];
}

/**
 * @return array<int, array<string, mixed>>
 */
function fetchUserMappingsForTransform(PDO $pdo): array
{
    $query = <<<'SQL'
        SELECT
            mm.mapping_id,
            mm.jira_account_id,
            mm.redmine_user_id,
            mm.migration_status,
            mm.match_type,
            mm.automation_hash,
            mm.proposed_redmine_login,
            mm.proposed_redmine_mail,
            mm.proposed_firstname,
            mm.proposed_lastname,
            mm.proposed_redmine_status,
            mm.notes,
            mm.jira_display_name,
            mm.jira_email_address,
            sj.account_id AS staging_account_id,
            sj.display_name AS staging_display_name,
            sj.email_address AS staging_email_address
        FROM migration_mapping_users mm
        LEFT JOIN staging_jira_users sj ON sj.account_id = mm.jira_account_id
        ORDER BY mm.mapping_id
    SQL;

    $statement = $pdo->query($query);
    if ($statement === false) {
        throw new RuntimeException('Failed to fetch migration mapping records for transformation.');
    }

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @return array<string, int>
 */
function fetchMigrationStatusCounts(PDO $pdo): array
{
    $statement = $pdo->query('SELECT migration_status, COUNT(*) AS total FROM migration_mapping_users GROUP BY migration_status ORDER BY migration_status');
    if ($statement === false) {
        throw new RuntimeException('Failed to count migration mapping statuses.');
    }

    $results = [];
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!isset($row['migration_status'], $row['total'])) {
            continue;
        }

        $status = (string)$row['migration_status'];
        $results[$status] = (int)$row['total'];
    }

    return $results;
}

function computeAutomationStateHash(
    ?int $redmineUserId,
    string $migrationStatus,
    string $matchType,
    ?string $proposedLogin,
    ?string $proposedMail,
    ?string $proposedFirstname,
    ?string $proposedLastname,
    string $proposedStatus,
    ?string $notes
): string {
    try {
        $payload = json_encode(
            [
                'redmine_user_id' => $redmineUserId,
                'migration_status' => $migrationStatus,
                'match_type' => $matchType,
                'proposed_redmine_login' => $proposedLogin,
                'proposed_redmine_mail' => $proposedMail,
                'proposed_firstname' => $proposedFirstname,
                'proposed_lastname' => $proposedLastname,
                'proposed_redmine_status' => $proposedStatus,
                'notes' => $notes,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    } catch (JsonException $exception) {
        throw new RuntimeException('Failed to encode automation state hash payload: ' . $exception->getMessage(), 0, $exception);
    }

    return hash('sha256', (string)$payload);
}

function normalizeStoredAutomationHash(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }

    $candidate = is_string($value) ? trim($value) : trim((string)$value);
    if ($candidate === '') {
        return null;
    }

    if (!preg_match('/^[0-9a-f]{64}$/i', $candidate)) {
        return null;
    }

    return strtolower($candidate);
}

function normalizeProposedRedmineStatus(mixed $value, string $defaultStatus): string
{
    if (is_string($value)) {
        $normalized = strtoupper(trim($value));
        if ($normalized === 'ACTIVE' || $normalized === 'LOCKED') {
            return $normalized;
        }
    }

    return $defaultStatus;
}

/**
 * @param array<string, mixed> $redmineUser
 */
function deriveRedmineStatusLabelFromSnapshot(array $redmineUser): string
{
    if (isset($redmineUser['status'])) {
        $statusCode = (int)$redmineUser['status'];
        if ($statusCode === 1) {
            return 'ACTIVE';
        }
    }

    return 'LOCKED';
}

/**
 * @return array{0: ?string, 1: ?string}
 */
function deriveNamePartsFromDisplayName(?string $displayName): array
{
    if ($displayName === null) {
        return [null, null];
    }

    $trimmed = trim($displayName);
    if ($trimmed === '') {
        return [null, null];
    }

    if (str_contains($trimmed, ',')) {
        [$lastNamePart, $firstNamePart] = explode(',', $trimmed, 2);
        $firstName = normalizeString($firstNamePart, 255);
        $lastName = normalizeString($lastNamePart, 255);
        if ($firstName !== null && $lastName !== null) {
            return [$firstName, $lastName];
        }
    }

    $parts = preg_split('/\s+/', $trimmed) ?: [];
    if (count($parts) >= 2) {
        $firstCandidate = array_shift($parts);
        $lastCandidate = array_pop($parts);
        if ($lastCandidate === null) {
            $lastCandidate = '';
        }
        if ($parts !== []) {
            $lastCandidate = implode(' ', array_merge($parts, [$lastCandidate]));
        }

        $firstName = normalizeString($firstCandidate, 255);
        $lastName = normalizeString($lastCandidate, 255);
        if ($firstName !== null && $lastName !== null) {
            return [$firstName, $lastName];
        }
    }

    return [null, null];
}

function lowercaseValue(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    if (function_exists('mb_strtolower')) {
        return mb_strtolower($trimmed);
    }

    return strtolower($trimmed);
}

/**
 * @throws GuzzleException
 */
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
            $extractedAt = formatCurrentUtcTimestamp('Y-m-d H:i:s');

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
                'retrieved_at' => formatCurrentUtcTimestamp('Y-m-d H:i:s'),
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
        $response = $client->get(sprintf('users/%d.json', $userId));
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

// Further transformation and load steps will be implemented in later iterations of this script.
