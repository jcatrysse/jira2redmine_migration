<?php /** @noinspection PhpArrayIndexImmediatelyRewrittenInspection */
/** @noinspection DuplicatedCode */
/** @noinspection SpellCheckingInspection */

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

const MIGRATE_PRIORITIES_SCRIPT_VERSION = '0.0.6';
const AVAILABLE_PHASES = [
    'jira' => 'Extract Jira issue priorities into staging_jira_priorities.',
    'redmine' => 'Refresh the Redmine issue priority snapshot from the REST API.',
    'transform' => 'Reconcile Jira and Redmine priorities to populate migration mappings.',
    'push' => 'Produce a manual action plan for creating missing Redmine priorities.',
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

    printf(
        "[%s] Selected phases: %s%s",
        formatCurrentTimestamp(),
        implode(', ', $phasesToRun),
        PHP_EOL
    );

    $databaseConfig = extractArrayConfig($config, 'database');
    $pdo = createDatabaseConnection($databaseConfig);

    if (in_array('jira', $phasesToRun, true)) {
        $jiraConfig = extractArrayConfig($config, 'jira');
        $jiraClient = createJiraClient($jiraConfig);

        printf("[%s] Starting Jira priority extraction...%s", formatCurrentTimestamp(), PHP_EOL);

        $totalJiraProcessed = fetchAndStoreJiraIssuePriorities($jiraClient, $pdo);

        printf(
            "[%s] Completed Jira extraction. %d priority records processed.%s",
            formatCurrentTimestamp(),
            $totalJiraProcessed,
            PHP_EOL
        );
    } else {
        printf(
            "[%s] Skipping Jira priority extraction (disabled via CLI option).%s",
            formatCurrentTimestamp(),
            PHP_EOL
        );
    }

    if (in_array('redmine', $phasesToRun, true)) {
        $redmineConfig = extractArrayConfig($config, 'redmine');
        $redmineClient = createRedmineClient($redmineConfig);

        printf("[%s] Starting Redmine priority snapshot...%s", formatCurrentTimestamp(), PHP_EOL);

        $totalRedmineProcessed = fetchAndStoreRedmineIssuePriorities($redmineClient, $pdo);

        printf(
            "[%s] Completed Redmine snapshot. %d priority records processed.%s",
            formatCurrentTimestamp(),
            $totalRedmineProcessed,
            PHP_EOL
        );
    } else {
        printf(
            "[%s] Skipping Redmine priority snapshot (disabled via CLI option).%s",
            formatCurrentTimestamp(),
            PHP_EOL
        );
    }

    if (in_array('transform', $phasesToRun, true)) {
        printf("[%s] Starting priority reconciliation & transform phase...%s", formatCurrentTimestamp(), PHP_EOL);

        $transformSummary = runPriorityTransformationPhase($pdo);

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
            printf("  Current priority mapping breakdown:%s", PHP_EOL);
            foreach ($transformSummary['status_counts'] as $status => $count) {
                printf("  - %-32s %d%s", $status, $count, PHP_EOL);
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

        runPriorityPushPhase($pdo, $confirmPush, $isDryRun);
    } else {
        printf(
            "[%s] Skipping push phase (disabled via CLI option).%s",
            formatCurrentTimestamp(),
            PHP_EOL
        );
    }
}

/**
 * @throws Throwable
 */
function fetchAndStoreJiraIssuePriorities(Client $client, PDO $pdo): int
{
    $insertStatement = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_priorities (id, name, description, raw_payload, extracted_at)
        VALUES (:id, :name, :description, :raw_payload, :extracted_at)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            description = VALUES(description),
            raw_payload = VALUES(raw_payload),
            extracted_at = VALUES(extracted_at)
    SQL);

    if ($insertStatement === false) {
        throw new RuntimeException('Failed to prepare insert statement for staging_jira_priorities.');
    }

    try {
        $response = $client->get('/rest/api/3/priority');
    } catch (BadResponseException $exception) {
        $response = $exception->getResponse();
        $message = 'Failed to fetch issue priorities from Jira';
        $message .= sprintf(' (HTTP %d)', $response->getStatusCode());

        throw new RuntimeException($message, 0, $exception);
    } catch (GuzzleException $exception) {
        throw new RuntimeException('Failed to fetch issue priorities from Jira: ' . $exception->getMessage(), 0, $exception);
    }

    $decoded = decodeJsonResponse($response);

    if (!is_array($decoded)) {
        throw new RuntimeException('Unexpected response when fetching issue priorities from Jira.');
    }

    $totalInserted = 0;
    $extractedAt = formatCurrentUtcTimestamp('Y-m-d H:i:s');

    $pdo->beginTransaction();

    try {
        foreach ($decoded as $priority) {
            if (!is_array($priority)) {
                continue;
            }

            $priorityId = isset($priority['id']) ? (string)$priority['id'] : '';
            $name = normalizeString($priority['name'] ?? null, 255);

            if ($priorityId === '' || $name === null) {
                continue;
            }

            $description = $priority['description'] ?? null;
            if (!is_string($description)) {
                $description = null;
            }

            try {
                $rawPayload = json_encode($priority, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (JsonException $exception) {
                throw new RuntimeException(
                    sprintf('Failed to encode Jira priority payload for %s: %s', $priorityId, $exception->getMessage()),
                    0,
                    $exception
                );
            }

            $insertStatement->execute([
                'id' => $priorityId,
                'name' => $name,
                'description' => $description,
                'raw_payload' => $rawPayload,
                'extracted_at' => $extractedAt,
            ]);

            $totalInserted++;
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    printf("  Processed %d Jira priority records.%s", $totalInserted, PHP_EOL);

    return $totalInserted;
}

/**
 * @throws Throwable
 */
function fetchAndStoreRedmineIssuePriorities(Client $client, PDO $pdo): int
{
    try {
        $pdo->exec('TRUNCATE TABLE staging_redmine_issue_priorities');
    } catch (PDOException $exception) {
        throw new RuntimeException('Unable to truncate staging_redmine_issue_priorities: ' . $exception->getMessage(), 0, $exception);
    }

    try {
        $response = $client->get('enumerations/issue_priorities.json');
    } catch (GuzzleException $exception) {
        throw new RuntimeException('Failed to fetch issue priorities from Redmine: ' . $exception->getMessage(), 0, $exception);
    }

    $decoded = decodeJsonResponse($response);

    if (!is_array($decoded) || !isset($decoded['issue_priorities']) || !is_array($decoded['issue_priorities'])) {
        throw new RuntimeException('Unexpected response when fetching issue priorities from Redmine.');
    }

    $insertStatement = $pdo->prepare(<<<SQL
        INSERT INTO staging_redmine_issue_priorities (id, name, is_default, raw_payload, retrieved_at)
        VALUES (:id, :name, :is_default, :raw_payload, :retrieved_at)
    SQL);

    if ($insertStatement === false) {
        throw new RuntimeException('Failed to prepare insert statement for staging_redmine_issue_priorities.');
    }

    $retrievedAt = formatCurrentUtcTimestamp('Y-m-d H:i:s');
    $totalInserted = 0;

    $pdo->beginTransaction();

    try {
        foreach ($decoded['issue_priorities'] as $priority) {
            if (!is_array($priority)) {
                continue;
            }

            $priorityId = isset($priority['id']) ? (int)$priority['id'] : 0;
            $name = normalizeString($priority['name'] ?? null, 255);

            if ($priorityId <= 0 || $name === null) {
                continue;
            }

            try {
                $rawPayload = json_encode($priority, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (JsonException $exception) {
                throw new RuntimeException(
                    sprintf('Failed to encode Redmine priority payload for %d: %s', $priorityId, $exception->getMessage()),
                    0,
                    $exception
                );
            }

            $isDefaultValue = normalizeBooleanDatabaseValue($priority['is_default'] ?? null);
            if ($isDefaultValue === null) {
                $isDefaultValue = 0;
            }

            $insertStatement->execute([
                'id' => $priorityId,
                'name' => $name,
                'is_default' => $isDefaultValue,
                'raw_payload' => $rawPayload,
                'retrieved_at' => $retrievedAt,
            ]);

            $totalInserted++;
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    printf("  Captured %d Redmine priority records.%s", $totalInserted, PHP_EOL);

    return $totalInserted;
}

/**
 * @return array{matched: int, ready_for_creation: int, manual_review: int, manual_overrides: int, skipped: int, unchanged: int, status_counts: array<string, int>}
 */
function runPriorityTransformationPhase(PDO $pdo): array
{
    syncPriorityMappings($pdo);
    refreshPriorityMetadata($pdo);

    $redmineLookup = buildRedminePriorityLookup($pdo);
    $mappings = fetchPriorityMappingsForTransform($pdo);

    $updateStatement = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_priorities
        SET
            redmine_priority_id = :redmine_priority_id,
            migration_status = :migration_status,
            notes = :notes,
            proposed_redmine_name = :proposed_redmine_name,
            proposed_is_default = :proposed_is_default,
            automation_hash = :automation_hash
        WHERE mapping_id = :mapping_id
    SQL);

    if ($updateStatement === false) {
        throw new RuntimeException('Failed to prepare update statement for migration_mapping_priorities.');
    }

    $summary = [
        'matched' => 0,
        'ready_for_creation' => 0,
        'manual_review' => 0,
        'manual_overrides' => 0,
        'skipped' => 0,
        'unchanged' => 0,
        'status_counts' => [],
    ];

    foreach ($mappings as $row) {
        $currentStatus = (string)$row['migration_status'];
        $allowedStatuses = ['PENDING_ANALYSIS', 'READY_FOR_CREATION', 'MATCH_FOUND', 'CREATION_FAILED'];
        if (!in_array($currentStatus, $allowedStatuses, true)) {
            $summary['skipped']++;
            continue;
        }

        $jiraPriorityId = (string)$row['jira_priority_id'];
        $jiraPriorityName = $row['jira_priority_name'] !== null ? (string)$row['jira_priority_name'] : null;

        $currentRedmineId = $row['redmine_priority_id'] !== null ? (int)$row['redmine_priority_id'] : null;
        $currentNotes = $row['notes'] !== null ? (string)$row['notes'] : null;
        $currentProposedName = $row['proposed_redmine_name'] !== null ? (string)$row['proposed_redmine_name'] : null;
        $currentProposedIsDefault = normalizeBooleanFlag($row['proposed_is_default'] ?? null);
        $storedAutomationHash = normalizeStoredAutomationHash($row['automation_hash'] ?? null);

        $currentAutomationHash = computePriorityAutomationStateHash(
            $currentRedmineId,
            $currentStatus,
            $currentProposedName,
            $currentProposedIsDefault,
            $currentNotes
        );

        if ($storedAutomationHash !== null && $storedAutomationHash !== $currentAutomationHash) {
            $summary['manual_overrides']++;
            printf(
                "  [preserved] Jira priority %s has manual overrides; skipping automated changes.%s",
                $jiraPriorityName ?? $jiraPriorityId,
                PHP_EOL
            );
            continue;
        }

        $defaultName = $jiraPriorityName !== null ? normalizeString($jiraPriorityName, 255) : null;

        $manualReason = null;
        $newStatus = $currentStatus;
        $newRedmineId = $currentRedmineId;
        $proposedName = $currentProposedName ?? $defaultName;
        $proposedIsDefault = $currentProposedIsDefault ?? false;

        if ($defaultName === null) {
            $manualReason = 'Missing Jira priority name in the staging snapshot.';
        } else {
            $lookupKey = strtolower($defaultName);
            $matchedRedmine = $redmineLookup[$lookupKey] ?? null;

            if ($matchedRedmine !== null) {
                $newStatus = 'MATCH_FOUND';
                $newRedmineId = (int)$matchedRedmine['id'];
                $proposedName = normalizeString($matchedRedmine['name'] ?? $defaultName, 255) ?? $defaultName;
                $proposedIsDefault = normalizeBooleanFlag($matchedRedmine['is_default'] ?? null) ?? false;
            } else {
                $newStatus = 'READY_FOR_CREATION';
                $newRedmineId = null;
                $proposedName = $defaultName;
                if ($currentProposedIsDefault !== null) {
                    $proposedIsDefault = $currentProposedIsDefault;
                } else {
                    $proposedIsDefault = false;
                }
            }
        }

        if ($manualReason !== null) {
            $newStatus = 'MANUAL_INTERVENTION_REQUIRED';
            $newRedmineId = null;
            if ($proposedName === null) {
                $proposedName = $jiraPriorityId;
            }
            $notes = $manualReason;

            printf(
                "  [manual] Jira priority %s: %s%s",
                $defaultName ?? $jiraPriorityId,
                $manualReason,
                PHP_EOL
            );
        } else {
            $notes = null;
        }

        if ($proposedName === null) {
            $proposedName = $defaultName ?? $jiraPriorityId;
        }

        $newAutomationHash = computePriorityAutomationStateHash(
            $newRedmineId,
            $newStatus,
            $proposedName,
            $proposedIsDefault,
            $notes
        );

        $needsUpdate = $currentRedmineId !== $newRedmineId
            || $currentStatus !== $newStatus
            || $currentProposedName !== $proposedName
            || $currentProposedIsDefault !== $proposedIsDefault
            || $currentNotes !== $notes
            || $storedAutomationHash !== $newAutomationHash;

        if (!$needsUpdate) {
            $summary['unchanged']++;
            continue;
        }

        $updateStatement->execute([
            'redmine_priority_id' => $newRedmineId,
            'migration_status' => $newStatus,
            'notes' => $notes,
            'proposed_redmine_name' => $proposedName,
            'proposed_is_default' => normalizeBooleanDatabaseValue($proposedIsDefault),
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

    $summary['status_counts'] = fetchPriorityMigrationStatusCounts($pdo);

    return $summary;
}

function syncPriorityMappings(PDO $pdo): void
{
    $sql = <<<SQL
        INSERT INTO migration_mapping_priorities (jira_priority_id, migration_status, notes, created_at, last_updated_at)
        SELECT jp.id, 'PENDING_ANALYSIS', NULL, NOW(), NOW()
        FROM staging_jira_priorities jp
        ON DUPLICATE KEY UPDATE last_updated_at = VALUES(last_updated_at)
    SQL;

    try {
        $pdo->exec($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to synchronise migration_mapping_priorities: ' . $exception->getMessage(), 0, $exception);
    }
}

function refreshPriorityMetadata(PDO $pdo): void
{
    $sql = <<<SQL
        UPDATE migration_mapping_priorities map
        INNER JOIN staging_jira_priorities jp ON jp.id = map.jira_priority_id
        SET
            map.jira_priority_name = jp.name,
            map.jira_priority_description = jp.description
        WHERE 1
    SQL;

    try {
        $pdo->exec($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to refresh Jira priority metadata in migration_mapping_priorities: ' . $exception->getMessage(), 0, $exception);
    }
}

/**
 * @return array<string, array{id: int, name: string, is_default: mixed}>
 */
function buildRedminePriorityLookup(PDO $pdo): array
{
    try {
        $statement = $pdo->query('SELECT id, name, is_default FROM staging_redmine_issue_priorities');
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to build Redmine priority lookup: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    /** @var array<int, array<string, mixed>> $rows */
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    $lookup = [];

    foreach ($rows as $row) {
        if (!isset($row['name'])) {
            continue;
        }

        $name = normalizeString($row['name'], 255);
        if ($name === null) {
            continue;
        }

        $lookup[strtolower($name)] = $row;
    }

    return $lookup;
}

/**
 * @return array<int, array<string, mixed>>
 */
function fetchPriorityMappingsForTransform(PDO $pdo): array
{
    $sql = <<<SQL
        SELECT
            map.mapping_id,
            map.jira_priority_id,
            map.jira_priority_name,
            map.jira_priority_description,
            map.redmine_priority_id,
            map.migration_status,
            map.notes,
            map.proposed_redmine_name,
            map.proposed_is_default,
            map.automation_hash
        FROM migration_mapping_priorities map
        ORDER BY map.jira_priority_name IS NULL, map.jira_priority_name, map.jira_priority_id
    SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to fetch migration mapping rows for priorities: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function computePriorityAutomationStateHash(?int $redminePriorityId, string $migrationStatus, ?string $proposedName, ?bool $proposedIsDefault, ?string $notes): string
{
    $payload = [
        'redmine_priority_id' => $redminePriorityId,
        'migration_status' => $migrationStatus,
        'proposed_redmine_name' => $proposedName,
        'proposed_is_default' => $proposedIsDefault,
        'notes' => $notes,
    ];

    try {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (JsonException $exception) {
        throw new RuntimeException('Failed to compute automation hash for priority mapping: ' . $exception->getMessage(), 0, $exception);
    }

    return hash('sha256', $json);
}

function fetchPriorityMigrationStatusCounts(PDO $pdo): array
{
    try {
        $statement = $pdo->query('SELECT migration_status, COUNT(*) AS total FROM migration_mapping_priorities GROUP BY migration_status');
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to compute priority migration breakdown: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    /** @var array<int, array<string, mixed>> $rows */
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    $results = [];

    foreach ($rows as $row) {
        if (!isset($row['migration_status'])) {
            continue;
        }

        $status = (string)$row['migration_status'];
        $results[$status] = isset($row['total']) ? (int)$row['total'] : 0;
    }

    return $results;
}

function runPriorityPushPhase(PDO $pdo, bool $confirmPush, bool $isDryRun): void
{
    printf("[%s] Starting push phase (manual priority checklist)...%s", formatCurrentTimestamp(), PHP_EOL);

    $pendingPriorities = fetchPrioritiesReadyForCreation($pdo);
    $pendingCount = count($pendingPriorities);

    if ($pendingCount === 0) {
        printf("  No Jira priorities are marked as READY_FOR_CREATION.%s", PHP_EOL);
        if ($isDryRun) {
            printf("  --dry-run flag enabled: no database changes will be made.%s", PHP_EOL);
        }
        if ($confirmPush) {
            printf("  --confirm-push provided but there is nothing to acknowledge.%s", PHP_EOL);
        } else {
            printf("  Provide --confirm-push after manually creating any outstanding priorities in Redmine.%s", PHP_EOL);
        }
        return;
    }

    printf("  %d priority(ies) require manual creation in Redmine.%s", $pendingCount, PHP_EOL);
    foreach ($pendingPriorities as $priority) {
        $jiraName = $priority['jira_priority_name'] ?? null;
        $jiraId = (string)$priority['jira_priority_id'];
        $proposedName = $priority['proposed_redmine_name'] ?? null;
        $proposedIsDefault = normalizeBooleanFlag($priority['proposed_is_default'] ?? null);
        $notes = $priority['notes'] ?? null;

        printf(
            "  - Jira priority: %s (ID: %s)%s",
            $jiraName ?? '[missing name]',
            $jiraId,
            PHP_EOL
        );
        printf(
            "    Proposed Redmine name: %s | Should be default: %s%s",
            $proposedName ?? ($jiraName ?? 'n/a'),
            formatBooleanForDisplay($proposedIsDefault),
            PHP_EOL
        );
        if ($notes !== null) {
            printf("    Notes: %s%s", $notes, PHP_EOL);
        }
    }

    if (!$confirmPush) {
        printf("  --confirm-push not supplied: mappings remain in READY_FOR_CREATION.%s", PHP_EOL);
        printf("  After creating the priorities manually, update migration_mapping_priorities with the Redmine IDs and set migration_status to CREATION_SUCCESS.%s", PHP_EOL);
        return;
    }

    if ($isDryRun) {
        printf("  --dry-run flag enabled: skipping acknowledgement despite --confirm-push.%s", PHP_EOL);
        return;
    }

    printf("  Manual acknowledgement recorded. Remember to update redmine_priority_id and migration_status once the priorities exist in Redmine.%s", PHP_EOL);
}

function fetchPrioritiesReadyForCreation(PDO $pdo): array
{
    $sql = <<<SQL
        SELECT
            map.mapping_id,
            map.jira_priority_id,
            map.jira_priority_name,
            map.proposed_redmine_name,
            map.proposed_is_default,
            map.notes
        FROM migration_mapping_priorities map
        WHERE map.migration_status = 'READY_FOR_CREATION'
        ORDER BY map.jira_priority_name IS NULL, map.jira_priority_name, map.jira_priority_id
    SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to fetch priorities ready for creation: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function formatBooleanForDisplay(?bool $value): string
{
    if ($value === null) {
        return 'unknown';
    }

    return $value ? 'yes' : 'no';
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

    $positional = [];

    $arguments = $argv;
    array_shift($arguments); // remove script name

    while ($arguments !== []) {
        $argument = array_shift($arguments);

        if ($argument === null) {
            break;
        }

        if ($argument === '--') {
            $positional = array_merge($positional, $arguments);
            break;
        }

        switch ($argument) {
            case '-h':
            case '--help':
                $options['help'] = true;
                break;
            case '-V':
            case '--version':
                $options['version'] = true;
                break;
            case '--confirm-push':
                $options['confirm_push'] = true;
                break;
            case '--dry-run':
                $options['dry_run'] = true;
                break;
            default:
                if (str_starts_with($argument, '--phases=')) {
                    $options['phases'] = substr($argument, 9);
                    break;
                }

                if (str_starts_with($argument, '--skip=')) {
                    $options['skip'] = substr($argument, 7);
                    break;
                }

                if ($argument !== '' && $argument[0] === '-') {
                    throw new RuntimeException(sprintf('Unknown option: %s', $argument));
                }

                $positional[] = $argument;
                break;
        }
    }

    return [$options, $positional];
}

function determinePhasesToRun(array $cliOptions): array
{
    $phases = array_keys(AVAILABLE_PHASES);

    $selected = determinePhaseList($cliOptions['phases']);
    $skipped = determinePhaseList($cliOptions['skip']);

    if ($selected !== []) {
        $phases = array_values(array_intersect($phases, $selected));
    }

    if ($skipped !== []) {
        $phases = array_values(array_diff($phases, $skipped));
    }

    if ($phases === []) {
        throw new RuntimeException('No phases selected after applying --phases and --skip filters.');
    }

    return $phases;
}

function determinePhaseList(?string $list): array
{
    if ($list === null || trim($list) === '') {
        return [];
    }

    $phases = [];
    foreach (explode(',', $list) as $candidate) {
        $normalized = strtolower(trim($candidate));
        if ($normalized === '') {
            continue;
        }

        if (!array_key_exists($normalized, AVAILABLE_PHASES)) {
            throw new RuntimeException(sprintf('Unknown phase: %s', $candidate));
        }

        $phases[] = $normalized;
    }

    return array_values(array_unique($phases));
}

function extractArrayConfig(array $config, string $key): array
{
    if (!isset($config[$key]) || !is_array($config[$key])) {
        throw new RuntimeException(sprintf('Missing configuration section: %s', $key));
    }

    return $config[$key];
}

function createDatabaseConnection(array $databaseConfig): PDO
{
    $dsn = (string)($databaseConfig['dsn'] ?? '');
    $username = (string)($databaseConfig['username'] ?? '');
    $password = (string)($databaseConfig['password'] ?? '');
    $options = isset($databaseConfig['options']) && is_array($databaseConfig['options']) ? $databaseConfig['options'] : [];

    if ($dsn === '') {
        throw new RuntimeException('Database configuration must include a DSN.');
    }

    $pdo = new PDO($dsn, $username, $password, $options);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

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

function decodeJsonResponse(ResponseInterface $response): mixed
{
    $body = (string)$response->getBody();

    try {
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Unable to decode JSON response: ' . $exception->getMessage(), 0, $exception);
    }
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

function normalizeBooleanFlag(mixed $value): ?bool
{
    $normalized = normalizeBooleanDatabaseValue($value);

    if ($normalized === null) {
        return null;
    }

    return $normalized === 1;
}

function normalizeBooleanDatabaseValue(mixed $value): ?int
{
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }

    if (is_int($value)) {
        if ($value === 1 || $value === 0) {
            return $value;
        }

        return $value > 0 ? 1 : 0;
    }

    if (is_string($value)) {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, ['1', 'true', 'yes', 'y'], true)) {
            return 1;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'n'], true)) {
            return 0;
        }
    }

    return null;
}

function normalizeStoredAutomationHash(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    if (!preg_match('/^[0-9a-f]{64}$/i', $trimmed)) {
        return null;
    }

    return strtolower($trimmed);
}

function printUsage(): void
{
    $scriptName = basename(__FILE__);

    echo sprintf(
        "%s (version %s)%s",
        $scriptName,
        MIGRATE_PRIORITIES_SCRIPT_VERSION,
        PHP_EOL
    );
    echo sprintf('Usage: php %s [options]%s', $scriptName, PHP_EOL);
    echo PHP_EOL;
    echo "Options:" . PHP_EOL;
    echo "  -h, --help           Display this help message and exit." . PHP_EOL;
    echo "  -V, --version        Print the script version and exit." . PHP_EOL;
    echo "      --phases=LIST    Comma-separated list of phases to execute." . PHP_EOL;
    echo "      --skip=LIST      Comma-separated list of phases to skip." . PHP_EOL;
    echo "      --confirm-push   Mark priorities as acknowledged after manual review." . PHP_EOL;
    echo "      --dry-run        Preview push-phase actions without updating migration status." . PHP_EOL;
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
    printf('%s version %s%s', basename(__FILE__), MIGRATE_PRIORITIES_SCRIPT_VERSION, PHP_EOL);
}
