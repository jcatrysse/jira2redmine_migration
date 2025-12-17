<?php
/** @noinspection PhpArrayIndexImmediatelyRewrittenInspection */
/** @noinspection DuplicatedCode */

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

const MIGRATE_SUBTASKS_SCRIPT_VERSION = '0.0.1';
const AVAILABLE_PHASES = [
    'analyse' => 'Summarise Jira subtask relationships and validate Redmine readiness.',
    'push' => 'Assign Redmine parent_issue_id values once both sides exist.',
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
        throw new RuntimeException(sprintf('Unexpected positional arguments: %s', implode(', ', $positionalArguments)));
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

    printf('[%s] Selected phases: %s%s', formatCurrentTimestamp(), implode(', ', $phasesToRun), PHP_EOL);

    $databaseConfig = extractArrayConfig($config, 'database');
    $pdo = createDatabaseConnection($databaseConfig);

    if (in_array('analyse', $phasesToRun, true)) {
        runSubtaskAnalysisPhase($pdo);
    } else {
        printf('[%s] Skipping analyse phase (disabled via CLI option).%s', formatCurrentTimestamp(), PHP_EOL);
    }

    if (in_array('push', $phasesToRun, true)) {
        $confirmPush = (bool)($cliOptions['confirm_push'] ?? false);
        $isDryRun = (bool)($cliOptions['dry_run'] ?? false);
        runSubtaskPushPhase($pdo, $config, $confirmPush, $isDryRun);
    } else {
        printf('[%s] Skipping push phase (disabled via CLI option).%s', formatCurrentTimestamp(), PHP_EOL);
    }
}

function printUsage(): void
{
    printf('Jira to Redmine Subtask Linking (step 13) — version %s%s', MIGRATE_SUBTASKS_SCRIPT_VERSION, PHP_EOL);
    printf("Usage: php 12_migrate_subtasks.php [options]\n\n");
    printf("Options:\n");
    printf("  --phases=LIST     Comma separated list of phases to run (default: analyse,push).\n");
    printf("  --skip=LIST       Comma separated list of phases to skip.\n");
    printf("  --confirm-push    Required to execute the push phase (updates Redmine).\n");
    printf("  --dry-run         Preview push work without calling Redmine.\n");
    printf("  --version         Display version information.\n");
    printf("  --help            Display this help message.\n");
}

function printVersion(): void
{
    printf('%s%s', MIGRATE_SUBTASKS_SCRIPT_VERSION, PHP_EOL);
}

/**
 * @throws Throwable
 */
function runSubtaskAnalysisPhase(PDO $pdo): void
{
    printf('[%s] Inspecting Jira subtask relationships...%s', formatCurrentTimestamp(), PHP_EOL);

    $rows = fetchSubtaskCandidates($pdo);
    if ($rows === []) {
        printf("  No Jira subtasks detected; nothing to analyse.%s", PHP_EOL);
        return;
    }

    $summary = [
        'total' => count($rows),
        'ready' => 0,
        'already_linked' => 0,
        'missing_parent' => 0,
        'missing_child' => 0,
        'manual_overrides' => 0,
    ];

    foreach ($rows as $row) {
        $childHasRedmine = !empty($row['redmine_issue_id']);
        $parentHasRedmine = !empty($row['parent_redmine_issue_id']);

        if (!$childHasRedmine) {
            $summary['missing_child']++;
            continue;
        }

        if (!$parentHasRedmine) {
            $summary['missing_parent']++;
            continue;
        }

        $currentHash = computeIssueAutomationStateHash(
            $row['redmine_issue_id'],
            $row['redmine_project_id'],
            $row['redmine_tracker_id'],
            $row['redmine_status_id'],
            $row['redmine_priority_id'],
            $row['redmine_author_id'],
            $row['redmine_assigned_to_id'],
            $row['redmine_parent_issue_id'],
            $row['proposed_project_id'],
            $row['proposed_tracker_id'],
            $row['proposed_status_id'],
            $row['proposed_priority_id'],
            $row['proposed_author_id'],
            $row['proposed_assigned_to_id'],
            $row['proposed_parent_issue_id'],
            $row['proposed_subject'],
            $row['proposed_description'],
            $row['proposed_start_date'],
            $row['proposed_due_date'],
            $row['proposed_done_ratio'],
            $row['proposed_estimated_hours'],
            $row['proposed_is_private'],
            $row['proposed_custom_field_payload']
        );
        $storedHash = normalizeStoredAutomationHash($row['automation_hash'] ?? null);
        if ($storedHash !== null && $storedHash !== $currentHash) {
            $summary['manual_overrides']++;
            continue;
        }

        $parentId = (int)$row['parent_redmine_issue_id'];
        $currentParentId = $row['redmine_parent_issue_id'] !== null ? (int)$row['redmine_parent_issue_id'] : null;
        if ($currentParentId === $parentId && $parentId !== 0) {
            $summary['already_linked']++;
            continue;
        }

        $summary['ready']++;
    }

    printf("  Total Jira subtasks: %d%s", $summary['total'], PHP_EOL);
    printf("  Ready to link: %d%s", $summary['ready'], PHP_EOL);
    printf("  Already linked: %d%s", $summary['already_linked'], PHP_EOL);
    printf("  Blocked (missing child issue in Redmine): %d%s", $summary['missing_child'], PHP_EOL);
    printf("  Blocked (missing parent issue in Redmine): %d%s", $summary['missing_parent'], PHP_EOL);
    printf("  Skipped due to manual overrides: %d%s", $summary['manual_overrides'], PHP_EOL);
}

/**
 * @param array<string, mixed> $config
 * @throws Throwable
 */
function runSubtaskPushPhase(PDO $pdo, array $config, bool $confirmPush, bool $isDryRun): void
{
    printf('[%s] Preparing Redmine subtask updates...%s', formatCurrentTimestamp(), PHP_EOL);

    $rows = fetchSubtaskCandidates($pdo);
    $candidates = [];
    foreach ($rows as $row) {
        if (empty($row['redmine_issue_id']) || empty($row['parent_redmine_issue_id'])) {
            continue;
        }

        $currentHash = computeIssueAutomationStateHash(
            $row['redmine_issue_id'],
            $row['redmine_project_id'],
            $row['redmine_tracker_id'],
            $row['redmine_status_id'],
            $row['redmine_priority_id'],
            $row['redmine_author_id'],
            $row['redmine_assigned_to_id'],
            $row['redmine_parent_issue_id'],
            $row['proposed_project_id'],
            $row['proposed_tracker_id'],
            $row['proposed_status_id'],
            $row['proposed_priority_id'],
            $row['proposed_author_id'],
            $row['proposed_assigned_to_id'],
            $row['proposed_parent_issue_id'],
            $row['proposed_subject'],
            $row['proposed_description'],
            $row['proposed_start_date'],
            $row['proposed_due_date'],
            $row['proposed_done_ratio'],
            $row['proposed_estimated_hours'],
            $row['proposed_is_private'],
            $row['proposed_custom_field_payload']
        );
        $storedHash = normalizeStoredAutomationHash($row['automation_hash'] ?? null);
        if ($storedHash !== null && $storedHash !== $currentHash) {
            printf(
                "  [preserved] Jira issue %s has manual overrides; skipping.%s",
                (string)$row['jira_issue_key'],
                PHP_EOL
            );
            continue;
        }

        $parentId = (int)$row['parent_redmine_issue_id'];
        $currentParentId = $row['redmine_parent_issue_id'] !== null ? (int)$row['redmine_parent_issue_id'] : null;
        if ($currentParentId === $parentId && $parentId !== 0) {
            continue;
        }

        $candidates[] = $row;
    }

    if ($candidates === []) {
        printf("  No pending subtask relationships detected.%s", PHP_EOL);
        return;
    }

    printf("  %d child issue(s) require parent updates.%s", count($candidates), PHP_EOL);

    if ($isDryRun) {
        foreach ($candidates as $row) {
            printf(
                "  [dry-run] Jira %s → parent %s (Redmine #%d → #%d)%s",
                (string)$row['jira_issue_key'],
                (string)($row['parent_issue_key'] ?? $row['jira_parent_issue_id']),
                (int)$row['redmine_issue_id'],
                (int)$row['parent_redmine_issue_id'],
                PHP_EOL
            );
        }
        printf("  Dry-run active; Redmine will not be updated.%s", PHP_EOL);
        return;
    }

    if (!$confirmPush) {
        printf("  Push confirmation missing; rerun with --confirm-push to update Redmine.%s", PHP_EOL);
        return;
    }

    $redmineConfig = extractArrayConfig($config, 'redmine');
    $client = createRedmineClient($redmineConfig);

    $successStatement = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_issues
        SET
            redmine_parent_issue_id = :redmine_parent_issue_id,
            proposed_parent_issue_id = :proposed_parent_issue_id,
            automation_hash = :automation_hash,
            notes = NULL,
            last_updated_at = CURRENT_TIMESTAMP
        WHERE mapping_id = :mapping_id
    SQL);
    if ($successStatement === false) {
        throw new RuntimeException('Failed to prepare success update statement.');
    }

    $failureStatement = $pdo->prepare('UPDATE migration_mapping_issues SET notes = :notes, last_updated_at = CURRENT_TIMESTAMP WHERE mapping_id = :mapping_id');
    if ($failureStatement === false) {
        throw new RuntimeException('Failed to prepare failure update statement.');
    }

    foreach ($candidates as $row) {
        $childRedmineId = (int)$row['redmine_issue_id'];
        $parentRedmineId = (int)$row['parent_redmine_issue_id'];
        $jiraIssueKey = (string)$row['jira_issue_key'];

        try {
            $client->put(sprintf('/issues/%d.json', $childRedmineId), [
                'json' => ['issue' => ['parent_issue_id' => $parentRedmineId]],
            ]);
        } catch (BadResponseException $exception) {
            $response = $exception->getResponse();
            $message = 'Failed to update Redmine parent';
            if ($response instanceof ResponseInterface) {
                $message .= sprintf(' (HTTP %d)', $response->getStatusCode());
                $message .= ': ' . extractErrorBody($response);
            }

            $failureStatement->execute([
                'mapping_id' => (int)$row['mapping_id'],
                'notes' => $message,
            ]);

            printf("  [error] %s for Jira issue %s.%s", $message, $jiraIssueKey, PHP_EOL);
            continue;
        } catch (GuzzleException $exception) {
            $message = 'Failed to update Redmine parent: ' . $exception->getMessage();
            $failureStatement->execute([
                'mapping_id' => (int)$row['mapping_id'],
                'notes' => $message,
            ]);

            printf("  [error] %s%s", $message, PHP_EOL);
            continue;
        }

        $updatedRow = $row;
        $updatedRow['redmine_parent_issue_id'] = $parentRedmineId;
        $updatedRow['proposed_parent_issue_id'] = $parentRedmineId;
        $newHash = computeIssueAutomationStateHash(
            $updatedRow['redmine_issue_id'],
            $updatedRow['redmine_project_id'],
            $updatedRow['redmine_tracker_id'],
            $updatedRow['redmine_status_id'],
            $updatedRow['redmine_priority_id'],
            $updatedRow['redmine_author_id'],
            $updatedRow['redmine_assigned_to_id'],
            $updatedRow['redmine_parent_issue_id'],
            $updatedRow['proposed_project_id'],
            $updatedRow['proposed_tracker_id'],
            $updatedRow['proposed_status_id'],
            $updatedRow['proposed_priority_id'],
            $updatedRow['proposed_author_id'],
            $updatedRow['proposed_assigned_to_id'],
            $updatedRow['proposed_parent_issue_id'],
            $updatedRow['proposed_subject'],
            $updatedRow['proposed_description'],
            $updatedRow['proposed_start_date'],
            $updatedRow['proposed_due_date'],
            $updatedRow['proposed_done_ratio'],
            $updatedRow['proposed_estimated_hours'],
            $updatedRow['proposed_is_private'],
            $updatedRow['proposed_custom_field_payload']
        );

        $successStatement->execute([
            'mapping_id' => (int)$row['mapping_id'],
            'redmine_parent_issue_id' => $parentRedmineId,
            'proposed_parent_issue_id' => $parentRedmineId,
            'automation_hash' => $newHash,
        ]);

        printf(
            "  [linked] Jira issue %s → Redmine #%d now has parent #%d%s",
            $jiraIssueKey,
            $childRedmineId,
            $parentRedmineId,
            PHP_EOL
        );
    }
}

/**
 * @return array<int, array<string, mixed>>
 * @throws Throwable
 */
function fetchSubtaskCandidates(PDO $pdo): array
{
    $sql = <<<SQL
        SELECT
            child.*,
            parent.redmine_issue_id AS parent_redmine_issue_id,
            parent.jira_issue_key AS parent_issue_key
        FROM migration_mapping_issues child
        LEFT JOIN migration_mapping_issues parent ON parent.jira_issue_id = child.jira_parent_issue_id
        WHERE child.jira_parent_issue_id IS NOT NULL
        ORDER BY child.mapping_id
    SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to fetch subtask candidates: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        throw new RuntimeException('Failed to fetch subtask candidates.');
    }

    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $statement->closeCursor();

    return $rows;
}

function determinePhasesToRun(array $cliOptions): array
{
    $defaultPhases = array_keys(AVAILABLE_PHASES);

    $phases = isset($cliOptions['phases']) && is_string($cliOptions['phases']) && $cliOptions['phases'] !== ''
        ? array_map('trim', explode(',', (string)$cliOptions['phases']))
        : $defaultPhases;

    $skips = isset($cliOptions['skip']) && is_string($cliOptions['skip']) && $cliOptions['skip'] !== ''
        ? array_map('trim', explode(',', (string)$cliOptions['skip']))
        : [];

    $phases = array_values(array_filter($phases, static function ($phase) use ($skips) {
        if ($phase === '') {
            return false;
        }

        return !in_array($phase, $skips, true);
    }));

    foreach ($phases as $phase) {
        if (!array_key_exists($phase, AVAILABLE_PHASES)) {
            throw new RuntimeException(sprintf('Unknown phase "%s". Supported phases: %s', $phase, implode(', ', array_keys(AVAILABLE_PHASES))));
        }
    }

    return $phases;
}

/**
 * @return array{0: array<string, mixed>, 1: array<int, string>}
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

    $positionals = [];

    array_shift($argv);
    foreach ($argv as $argument) {
        if ($argument === '--help') {
            $options['help'] = true;
        } elseif ($argument === '--version') {
            $options['version'] = true;
        } elseif (str_starts_with($argument, '--phases=')) {
            $options['phases'] = substr($argument, 9);
        } elseif (str_starts_with($argument, '--skip=')) {
            $options['skip'] = substr($argument, 7);
        } elseif ($argument === '--confirm-push') {
            $options['confirm_push'] = true;
        } elseif ($argument === '--dry-run') {
            $options['dry_run'] = true;
        } elseif ($argument === '') {
            continue;
        } elseif ($argument[0] === '-') {
            throw new RuntimeException(sprintf('Unknown option: %s', $argument));
        } else {
            $positionals[] = $argument;
        }
    }

    return [$options, $positionals];
}

function extractArrayConfig(array $config, string $key): array
{
    if (!isset($config[$key]) || !is_array($config[$key])) {
        throw new RuntimeException(sprintf('Missing %s configuration block.', $key));
    }

    return $config[$key];
}

function createDatabaseConnection(array $databaseConfig): PDO
{
    $host = (string)($databaseConfig['host'] ?? 'localhost');
    $port = (int)($databaseConfig['port'] ?? 3306);
    $dbname = (string)($databaseConfig['dbname'] ?? 'migration');
    $user = (string)($databaseConfig['user'] ?? 'root');
    $password = (string)($databaseConfig['password'] ?? '');

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbname);

    try {
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $exception) {
        throw new RuntimeException('Unable to connect to the database: ' . $exception->getMessage(), 0, $exception);
    }

    return $pdo;
}

function createRedmineClient(array $redmineConfig): Client
{
    $baseUri = (string)($redmineConfig['base_uri'] ?? '');
    if ($baseUri === '') {
        throw new RuntimeException('Redmine base_uri is required.');
    }

    $apiKey = (string)($redmineConfig['api_key'] ?? '');
    if ($apiKey === '') {
        throw new RuntimeException('Redmine API key is required.');
    }

    return new Client([
        'base_uri' => $baseUri,
        'headers' => [
            'X-Redmine-API-Key' => $apiKey,
            'Accept' => 'application/json',
        ],
    ]);
}

function formatCurrentTimestamp(string $format = 'Y-m-d H:i:s'): string
{
    return (new DateTimeImmutable('now'))->format($format);
}

function normalizeStoredAutomationHash(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }

    $trimmed = trim((string)$value);
    return $trimmed !== '' ? $trimmed : null;
}

function computeIssueAutomationStateHash(
    mixed $redmineIssueId,
    mixed $redmineProjectId,
    mixed $redmineTrackerId,
    mixed $redmineStatusId,
    mixed $redminePriorityId,
    mixed $redmineAuthorId,
    mixed $redmineAssigneeId,
    mixed $redmineParentIssueId,
    mixed $proposedProjectId,
    mixed $proposedTrackerId,
    mixed $proposedStatusId,
    mixed $proposedPriorityId,
    mixed $proposedAuthorId,
    mixed $proposedAssigneeId,
    mixed $proposedParentIssueId,
    mixed $proposedSubject,
    mixed $proposedDescription,
    mixed $proposedStartDate,
    mixed $proposedDueDate,
    mixed $proposedDoneRatio,
    mixed $proposedEstimatedHours,
    mixed $proposedIsPrivate,
    mixed $proposedCustomFieldPayload
): string {
    $payload = [
        'redmine_issue_id' => $redmineIssueId,
        'redmine_project_id' => $redmineProjectId,
        'redmine_tracker_id' => $redmineTrackerId,
        'redmine_status_id' => $redmineStatusId,
        'redmine_priority_id' => $redminePriorityId,
        'redmine_author_id' => $redmineAuthorId,
        'redmine_assigned_to_id' => $redmineAssigneeId,
        'redmine_parent_issue_id' => $redmineParentIssueId,
        'proposed_project_id' => $proposedProjectId,
        'proposed_tracker_id' => $proposedTrackerId,
        'proposed_status_id' => $proposedStatusId,
        'proposed_priority_id' => $proposedPriorityId,
        'proposed_author_id' => $proposedAuthorId,
        'proposed_assigned_to_id' => $proposedAssigneeId,
        'proposed_parent_issue_id' => $proposedParentIssueId,
        'proposed_subject' => $proposedSubject,
        'proposed_description' => $proposedDescription,
        'proposed_start_date' => $proposedStartDate,
        'proposed_due_date' => $proposedDueDate,
        'proposed_done_ratio' => $proposedDoneRatio,
        'proposed_estimated_hours' => $proposedEstimatedHours,
        'proposed_is_private' => $proposedIsPrivate,
        'proposed_custom_field_payload' => $proposedCustomFieldPayload,
    ];

    try {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (JsonException $exception) {
        throw new RuntimeException('Failed to encode issue automation payload: ' . $exception->getMessage(), 0, $exception);
    }

    return hash('sha256', $encoded);
}

function extractErrorBody(ResponseInterface $response): string
{
    $body = (string)$response->getBody();
    if ($body === '') {
        return '[empty response]';
    }

    $decoded = json_decode($body, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        if (isset($decoded['errors']) && is_array($decoded['errors'])) {
            return implode('; ', array_map(static fn($error) => is_string($error) ? $error : json_encode($error), $decoded['errors']));
        }

        if (isset($decoded['error']) && is_string($decoded['error'])) {
            return $decoded['error'];
        }
    }

    return trim($body);
}
