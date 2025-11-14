<?php /** @noinspection PhpArrayIndexImmediatelyRewrittenInspection */
/** @noinspection DuplicatedCode */
/** @noinspection SpellCheckingInspection */

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

const MIGRATE_STATUSES_SCRIPT_VERSION = '0.0.10';
const AVAILABLE_PHASES = [
    'jira' => 'Extract Jira issue statuses into staging_jira_statuses.',
    'redmine' => 'Refresh the Redmine issue status snapshot from the REST API.',
    'transform' => 'Reconcile Jira and Redmine statuses to populate migration mappings.',
    'push' => 'Produce a manual action plan or call the extended API to create missing Redmine statuses.',
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
 * @param array{help: bool, version: bool, phases: ?string, skip: ?string, confirm_push: bool, dry_run: bool, use_extended_api: bool} $cliOptions
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

    /** @var array<string, mixed>|null $redmineConfig */
    $redmineConfig = null;

    if (in_array('jira', $phasesToRun, true)) {
        $jiraConfig = extractArrayConfig($config, 'jira');
        $jiraClient = createJiraClient($jiraConfig);

        printf("[%s] Starting Jira issue status extraction...%s", formatCurrentTimestamp(), PHP_EOL);

        $totalJiraProcessed = fetchAndStoreJiraIssueStatuses($jiraClient, $pdo);

        printf(
            "[%s] Completed Jira extraction. %d status records processed.%s",
            formatCurrentTimestamp(),
            $totalJiraProcessed,
            PHP_EOL
        );
    } else {
        printf(
            "[%s] Skipping Jira issue status extraction (disabled via CLI option).%s",
            formatCurrentTimestamp(),
            PHP_EOL
        );
    }

    if (in_array('redmine', $phasesToRun, true)) {
        $redmineConfig ??= extractArrayConfig($config, 'redmine');
        $redmineClient = createRedmineClient($redmineConfig);

        printf("[%s] Starting Redmine issue status snapshot...%s", formatCurrentTimestamp(), PHP_EOL);

        $totalRedmineProcessed = fetchAndStoreRedmineIssueStatuses($redmineClient, $pdo);

        printf(
            "[%s] Completed Redmine snapshot. %d status records processed.%s",
            formatCurrentTimestamp(),
            $totalRedmineProcessed,
            PHP_EOL
        );
    } else {
        printf(
            "[%s] Skipping Redmine issue status snapshot (disabled via CLI option).%s",
            formatCurrentTimestamp(),
            PHP_EOL
        );
    }

    if (in_array('transform', $phasesToRun, true)) {
        printf("[%s] Starting status reconciliation & transform phase...%s", formatCurrentTimestamp(), PHP_EOL);

        $transformSummary = runStatusTransformationPhase($pdo);

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
            printf("  Current status mapping breakdown:%s", PHP_EOL);
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
        $redmineConfig ??= extractArrayConfig($config, 'redmine');
        $useExtendedApi = shouldUseExtendedApi($redmineConfig, (bool)($cliOptions['use_extended_api'] ?? false));

        runStatusPushPhase($pdo, $confirmPush, $isDryRun, $redmineConfig, $useExtendedApi);
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
function fetchAndStoreJiraIssueStatuses(Client $client, PDO $pdo): int
{
    $insertStatement = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_statuses (id, name, description, status_category_key, raw_payload, extracted_at)
        VALUES (:id, :name, :description, :status_category_key, :raw_payload, :extracted_at)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            description = VALUES(description),
            status_category_key = VALUES(status_category_key),
            raw_payload = VALUES(raw_payload),
            extracted_at = VALUES(extracted_at)
    SQL);

    if ($insertStatement === false) {
        throw new RuntimeException('Failed to prepare insert statement for staging_jira_statuses.');
    }

    try {
        $response = $client->get('/rest/api/3/status');
    } catch (BadResponseException $exception) {
        $response = $exception->getResponse();
        $message = 'Failed to fetch issue statuses from Jira';
        $message .= sprintf(' (HTTP %d)', $response->getStatusCode());

        throw new RuntimeException($message, 0, $exception);
    } catch (GuzzleException $exception) {
        throw new RuntimeException('Failed to fetch issue statuses from Jira: ' . $exception->getMessage(), 0, $exception);
    }

    $decoded = decodeJsonResponse($response);

    if (!is_array($decoded)) {
        throw new RuntimeException('Unexpected response when fetching issue statuses from Jira.');
    }

    $totalInserted = 0;
    $extractedAt = formatCurrentUtcTimestamp('Y-m-d H:i:s');

    $pdo->beginTransaction();

    try {
        foreach ($decoded as $status) {
            if (!is_array($status)) {
                continue;
            }

            $statusId = isset($status['id']) ? (string)$status['id'] : '';
            $name = normalizeString($status['name'] ?? null, 255);

            if ($statusId === '' || $name === null) {
                continue;
            }

            $description = $status['description'] ?? null;
            if (!is_string($description)) {
                $description = null;
            }

            $statusCategoryKey = null;
            if (isset($status['statusCategory']) && is_array($status['statusCategory'])) {
                $statusCategoryKey = normalizeString($status['statusCategory']['key'] ?? null, 100);
            }

            try {
                $rawPayload = json_encode($status, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (JsonException $exception) {
                throw new RuntimeException(
                    sprintf('Failed to encode Jira status payload for %s: %s', $statusId, $exception->getMessage()),
                    0,
                    $exception
                );
            }

            $insertStatement->execute([
                'id' => $statusId,
                'name' => $name,
                'description' => $description,
                'status_category_key' => $statusCategoryKey,
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

    printf("  Processed %d Jira status records.%s", $totalInserted, PHP_EOL);

    return $totalInserted;
}

/**
 * @throws Throwable
 */
function fetchAndStoreRedmineIssueStatuses(Client $client, PDO $pdo): int
{
    try {
        $pdo->exec('TRUNCATE TABLE staging_redmine_issue_statuses');
    } catch (PDOException $exception) {
        throw new RuntimeException('Unable to truncate staging_redmine_issue_statuses: ' . $exception->getMessage(), 0, $exception);
    }

    try {
        $response = $client->get('issue_statuses.json');
    } catch (GuzzleException $exception) {
        throw new RuntimeException('Failed to fetch issue statuses from Redmine: ' . $exception->getMessage(), 0, $exception);
    }

    $decoded = decodeJsonResponse($response);

    if (!is_array($decoded) || !isset($decoded['issue_statuses']) || !is_array($decoded['issue_statuses'])) {
        throw new RuntimeException('Unexpected response when fetching issue statuses from Redmine.');
    }

    $insertStatement = $pdo->prepare(<<<SQL
        INSERT INTO staging_redmine_issue_statuses (id, name, is_closed, raw_payload, retrieved_at)
        VALUES (:id, :name, :is_closed, :raw_payload, :retrieved_at)
    SQL);

    if ($insertStatement === false) {
        throw new RuntimeException('Failed to prepare insert statement for staging_redmine_issue_statuses.');
    }

    $retrievedAt = formatCurrentUtcTimestamp('Y-m-d H:i:s');
    $totalInserted = 0;

    $pdo->beginTransaction();

    try {
        foreach ($decoded['issue_statuses'] as $status) {
            if (!is_array($status)) {
                continue;
            }

            $statusId = isset($status['id']) ? (int)$status['id'] : 0;
            $name = normalizeString($status['name'] ?? null, 255);

            if ($statusId <= 0 || $name === null) {
                continue;
            }

            try {
                $rawPayload = json_encode($status, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (JsonException $exception) {
                throw new RuntimeException(
                    sprintf('Failed to encode Redmine issue status payload for %d: %s', $statusId, $exception->getMessage()),
                    0,
                    $exception
                );
            }

            $isClosedValue = normalizeBooleanDatabaseValue($status['is_closed'] ?? null);
            if ($isClosedValue === null) {
                $isClosedValue = 0;
            }

            $insertStatement->execute([
                'id' => $statusId,
                'name' => $name,
                'is_closed' => $isClosedValue,
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

    printf("  Captured %d Redmine status records.%s", $totalInserted, PHP_EOL);

    return $totalInserted;
}

/**
 * @return array{matched: int, ready_for_creation: int, manual_review: int, manual_overrides: int, skipped: int, unchanged: int, status_counts: array<string, int>}
 */
function runStatusTransformationPhase(PDO $pdo): array
{
    syncStatusMappings($pdo);
    refreshStatusMetadata($pdo);

    $redmineLookup = buildRedmineStatusLookup($pdo);
    $mappings = fetchStatusMappingsForTransform($pdo);

    $updateStatement = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_statuses
        SET
            redmine_status_id = :redmine_status_id,
            migration_status = :migration_status,
            notes = :notes,
            proposed_redmine_name = :proposed_redmine_name,
            proposed_is_closed = :proposed_is_closed,
            automation_hash = :automation_hash
        WHERE mapping_id = :mapping_id
    SQL);

    if ($updateStatement === false) {
        throw new RuntimeException('Failed to prepare update statement for migration_mapping_statuses.');
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

        $jiraStatusId = (string)$row['jira_status_id'];
        $jiraStatusName = $row['jira_status_name'] !== null ? (string)$row['jira_status_name'] : null;
        $jiraCategoryKey = $row['jira_status_category_key'] !== null ? strtolower((string)$row['jira_status_category_key']) : null;

        $currentRedmineId = $row['redmine_status_id'] !== null ? (int)$row['redmine_status_id'] : null;
        $currentNotes = $row['notes'] !== null ? (string)$row['notes'] : null;
        $currentProposedName = $row['proposed_redmine_name'] !== null ? (string)$row['proposed_redmine_name'] : null;
        $currentProposedIsClosed = normalizeBooleanFlag($row['proposed_is_closed'] ?? null);
        $storedAutomationHash = normalizeStoredAutomationHash($row['automation_hash'] ?? null);

        $currentAutomationHash = computeStatusAutomationStateHash(
            $currentRedmineId,
            $currentStatus,
            $currentProposedName,
            $currentProposedIsClosed,
            $currentNotes
        );

        if ($storedAutomationHash !== null && $storedAutomationHash !== $currentAutomationHash) {
            $summary['manual_overrides']++;
            printf(
                "  [preserved] Jira status %s has manual overrides; skipping automated changes.%s",
                $jiraStatusName ?? $jiraStatusId,
                PHP_EOL
            );
            continue;
        }

        $defaultName = $jiraStatusName !== null ? normalizeString($jiraStatusName, 255) : null;
        $defaultIsClosed = null;
        if ($jiraCategoryKey !== null) {
            if ($jiraCategoryKey === 'done') {
                $defaultIsClosed = true;
            } elseif (in_array($jiraCategoryKey, ['todo', 'indeterminate', 'new'], true)) {
                $defaultIsClosed = false;
            }
        }

        $manualReason = null;
        $newStatus = $currentStatus;
        $newRedmineId = $currentRedmineId;
        $proposedName = $currentProposedName ?? $defaultName;
        $proposedIsClosed = $currentProposedIsClosed ?? $defaultIsClosed;

        if ($defaultName === null) {
            $manualReason = 'Missing Jira status name in the staging snapshot.';
        } else {
            $lookupKey = strtolower($defaultName);
            $matchedRedmine = $redmineLookup[$lookupKey] ?? null;

            if ($matchedRedmine !== null) {
                $newStatus = 'MATCH_FOUND';
                $newRedmineId = (int)$matchedRedmine['id'];
                $proposedName = normalizeString($matchedRedmine['name'] ?? $defaultName, 255) ?? $defaultName;
                $proposedIsClosed = normalizeBooleanFlag($matchedRedmine['is_closed'] ?? null);
            } else {
                $newStatus = 'READY_FOR_CREATION';
                $newRedmineId = null;
                $proposedName = $defaultName;
                if ($proposedIsClosed === null) {
                    $proposedIsClosed = $defaultIsClosed;
                }
            }
        }

        if ($manualReason === null && $proposedIsClosed === null) {
            $manualReason = 'Unable to derive closed/open flag from the Jira status category.';
        }

        if ($manualReason !== null) {
            $newStatus = 'MANUAL_INTERVENTION_REQUIRED';
            $newRedmineId = null;
            if ($proposedName === null) {
                $proposedName = $defaultName ?? $jiraStatusId;
            }
            $notes = $manualReason;

            printf(
                "  [manual] Jira status %s: %s%s",
                $defaultName ?? $jiraStatusId,
                $manualReason,
                PHP_EOL
            );
        } else {
            $notes = null;
        }

        if ($proposedName === null) {
            $proposedName = $defaultName ?? $jiraStatusId;
        }

        $newAutomationHash = computeStatusAutomationStateHash(
            $newRedmineId,
            $newStatus,
            $proposedName,
            $proposedIsClosed,
            $notes
        );

        $needsUpdate = $currentRedmineId !== $newRedmineId
            || $currentStatus !== $newStatus
            || $currentProposedName !== $proposedName
            || $currentProposedIsClosed !== $proposedIsClosed
            || $currentNotes !== $notes
            || $storedAutomationHash !== $newAutomationHash;

        if (!$needsUpdate) {
            $summary['unchanged']++;
            continue;
        }

        $updateStatement->execute([
            'redmine_status_id' => $newRedmineId,
            'migration_status' => $newStatus,
            'notes' => $notes,
            'proposed_redmine_name' => $proposedName,
            'proposed_is_closed' => normalizeBooleanDatabaseValue($proposedIsClosed),
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

    $summary['status_counts'] = fetchStatusMigrationStatusCounts($pdo);

    return $summary;
}

function syncStatusMappings(PDO $pdo): void
{
    $sql = <<<SQL
        INSERT INTO migration_mapping_statuses (jira_status_id, migration_status, notes, created_at, last_updated_at)
        SELECT js.id, 'PENDING_ANALYSIS', NULL, NOW(), NOW()
        FROM staging_jira_statuses js
        ON DUPLICATE KEY UPDATE last_updated_at = VALUES(last_updated_at)
    SQL;

    try {
        $pdo->exec($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to synchronise migration_mapping_statuses: ' . $exception->getMessage(), 0, $exception);
    }
}

function refreshStatusMetadata(PDO $pdo): void
{
    $sql = <<<SQL
        UPDATE migration_mapping_statuses map
        INNER JOIN staging_jira_statuses js ON js.id = map.jira_status_id
        SET
            map.jira_status_name = js.name,
            map.jira_status_category_key = js.status_category_key
        WHERE 1
    SQL;

    try {
        $pdo->exec($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to refresh Jira status metadata in migration_mapping_statuses: ' . $exception->getMessage(), 0, $exception);
    }
}

/**
 * @return array<string, array{id: int, name: string, is_closed: mixed}>
 */
function buildRedmineStatusLookup(PDO $pdo): array
{
    try {
        $statement = $pdo->query('SELECT id, name, is_closed FROM staging_redmine_issue_statuses');
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to build Redmine status lookup: ' . $exception->getMessage(), 0, $exception);
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
function fetchStatusMappingsForTransform(PDO $pdo): array
{
    $sql = <<<SQL
        SELECT
            map.mapping_id,
            map.jira_status_id,
            map.jira_status_name,
            map.jira_status_category_key,
            map.redmine_status_id,
            map.migration_status,
            map.notes,
            map.proposed_redmine_name,
            map.proposed_is_closed,
            map.automation_hash
        FROM migration_mapping_statuses map
        ORDER BY map.jira_status_name IS NULL, map.jira_status_name, map.jira_status_id
    SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to fetch migration mapping rows for statuses: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function computeStatusAutomationStateHash(?int $redmineStatusId, string $migrationStatus, ?string $proposedName, ?bool $proposedIsClosed, ?string $notes): string
{
    $payload = [
        'redmine_status_id' => $redmineStatusId,
        'migration_status' => $migrationStatus,
        'proposed_redmine_name' => $proposedName,
        'proposed_is_closed' => $proposedIsClosed,
        'notes' => $notes,
    ];

    try {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (JsonException $exception) {
        throw new RuntimeException('Failed to compute automation hash for status mapping: ' . $exception->getMessage(), 0, $exception);
    }

    return hash('sha256', $json);
}

function fetchStatusMigrationStatusCounts(PDO $pdo): array
{
    try {
        $statement = $pdo->query('SELECT migration_status, COUNT(*) AS total FROM migration_mapping_statuses GROUP BY migration_status');
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to compute status migration breakdown: ' . $exception->getMessage(), 0, $exception);
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

function runStatusPushPhase(PDO $pdo, bool $confirmPush, bool $isDryRun, array $redmineConfig, bool $useExtendedApi): void
{
    $pendingStatuses = fetchStatusesReadyForCreation($pdo);
    $pendingCount = count($pendingStatuses);

    if ($useExtendedApi) {
        printf("[%s] Starting push phase (Redmine extended API)...%s", formatCurrentTimestamp(), PHP_EOL);

        if ($pendingCount === 0) {
            printf("  No Jira statuses are marked as READY_FOR_CREATION.%s", PHP_EOL);
            if ($isDryRun) {
                printf("  --dry-run flag enabled: no API calls will be made.%s", PHP_EOL);
            }
            if ($confirmPush) {
                printf("  --confirm-push provided but there is nothing to process.%s", PHP_EOL);
            }
            return;
        }

        $redmineClient = createRedmineClient($redmineConfig);
        $extendedApiPrefix = resolveExtendedApiPrefix($redmineConfig);
        verifyExtendedApiAvailability($redmineClient, $extendedApiPrefix, 'issue_statuses.json');

        $endpoint = buildExtendedApiPath($extendedApiPrefix, 'issue_statuses.json');

        printf("  %d status(es) queued for creation via the extended API.%s", $pendingCount, PHP_EOL);
        foreach ($pendingStatuses as $status) {
            $jiraName = $status['jira_status_name'] ?? null;
            $jiraId = (string)$status['jira_status_id'];
            $proposedName = $status['proposed_redmine_name'] ?? null;
            $proposedIsClosed = normalizeBooleanFlag($status['proposed_is_closed'] ?? null);
            $categoryKey = $status['jira_status_category_key'] ?? null;
            $notes = $status['notes'] ?? null;

            $effectiveName = $proposedName ?? ($jiraName ?? $jiraId);
            $effectiveIsClosed = $proposedIsClosed ?? false;

            printf(
                "  - Jira status %s (ID: %s) -> Redmine \"%s\" (closed: %s, category: %s).%s",
                $jiraName ?? '[missing name]',
                $jiraId,
                $effectiveName,
                formatBooleanForDisplay($effectiveIsClosed),
                $categoryKey ?? 'unknown',
                PHP_EOL
            );
            if ($notes !== null) {
                printf("    Notes: %s%s", $notes, PHP_EOL);
            }
        }

        if (!$confirmPush) {
            printf("  --confirm-push missing: reviewed payloads only, no data was sent to Redmine.%s", PHP_EOL);
            return;
        }

        if ($isDryRun) {
            printf("  --dry-run enabled: skipping API calls after previewing the payloads.%s", PHP_EOL);
            return;
        }

        $updateStatement = $pdo->prepare(<<<SQL
            UPDATE migration_mapping_statuses
            SET
                redmine_status_id = :redmine_status_id,
                migration_status = :migration_status,
                notes = :notes,
                proposed_redmine_name = :proposed_redmine_name,
                proposed_is_closed = :proposed_is_closed,
                automation_hash = :automation_hash
            WHERE mapping_id = :mapping_id
        SQL);

        if ($updateStatement === false) {
            throw new RuntimeException('Failed to prepare update statement for migration_mapping_statuses during the push phase.');
        }

        $successCount = 0;
        $failureCount = 0;

        foreach ($pendingStatuses as $status) {
            $mappingId = (int)$status['mapping_id'];
            $jiraId = (string)$status['jira_status_id'];
            $jiraName = $status['jira_status_name'] ?? null;
            $proposedName = $status['proposed_redmine_name'] ?? null;
            $proposedIsClosed = normalizeBooleanFlag($status['proposed_is_closed'] ?? null) ?? false;
            $notes = $status['notes'] ?? null;

            $effectiveName = $proposedName ?? ($jiraName ?? $jiraId);

            $payload = [
                'issue_status' => [
                    'name' => $effectiveName,
                    'is_closed' => $proposedIsClosed,
                ],
            ];

            try {
                $response = $redmineClient->post($endpoint, ['json' => $payload]);
                $decoded = decodeJsonResponse($response);
                $newStatusId = extractCreatedIssueStatusId($decoded);

                $automationHash = computeStatusAutomationStateHash(
                    $newStatusId,
                    'CREATION_SUCCESS',
                    $effectiveName,
                    $proposedIsClosed,
                    null
                );

                $updateStatement->execute([
                    'redmine_status_id' => $newStatusId,
                    'migration_status' => 'CREATION_SUCCESS',
                    'notes' => null,
                    'proposed_redmine_name' => $effectiveName,
                    'proposed_is_closed' => normalizeBooleanDatabaseValue($proposedIsClosed),
                    'automation_hash' => $automationHash,
                    'mapping_id' => $mappingId,
                ]);

                printf(
                    "  [created] Jira status %s (%s) -> Redmine status #%d.%s",
                    $jiraName ?? $jiraId,
                    $jiraId,
                    $newStatusId,
                    PHP_EOL
                );

                $successCount++;
            } catch (Throwable $exception) {
                $errorMessage = summarizeExtendedApiError($exception);
                $automationHash = computeStatusAutomationStateHash(
                    null,
                    'CREATION_FAILED',
                    $effectiveName,
                    $proposedIsClosed,
                    $errorMessage
                );

                $updateStatement->execute([
                    'redmine_status_id' => null,
                    'migration_status' => 'CREATION_FAILED',
                    'notes' => $errorMessage,
                    'proposed_redmine_name' => $effectiveName,
                    'proposed_is_closed' => normalizeBooleanDatabaseValue($proposedIsClosed),
                    'automation_hash' => $automationHash,
                    'mapping_id' => $mappingId,
                ]);

                printf(
                    "  [failed] Jira status %s (%s): %s%s",
                    $jiraName ?? $jiraId,
                    $jiraId,
                    $errorMessage,
                    PHP_EOL
                );

                if ($notes !== null) {
                    printf("    Previous notes: %s%s", $notes, PHP_EOL);
                }

                $failureCount++;
            }
        }

        printf(
            "  Completed extended API push. Success: %d, Failed: %d.%s",
            $successCount,
            $failureCount,
            PHP_EOL
        );

        return;
    }

    printf("[%s] Starting push phase (manual status checklist)...%s", formatCurrentTimestamp(), PHP_EOL);

    if ($pendingCount === 0) {
        printf("  No Jira statuses are marked as READY_FOR_CREATION.%s", PHP_EOL);
        if ($isDryRun) {
            printf("  --dry-run flag enabled: no database changes will be made.%s", PHP_EOL);
        }
        if ($confirmPush) {
            printf("  --confirm-push provided but there is nothing to acknowledge.%s", PHP_EOL);
        } else {
            printf("  Provide --confirm-push after manually creating any outstanding statuses in Redmine.%s", PHP_EOL);
        }
        return;
    }

    printf("  %d status(es) require manual creation in Redmine.%s", $pendingCount, PHP_EOL);
    foreach ($pendingStatuses as $status) {
        $jiraName = $status['jira_status_name'] ?? null;
        $jiraId = (string)$status['jira_status_id'];
        $proposedName = $status['proposed_redmine_name'] ?? null;
        $proposedIsClosed = normalizeBooleanFlag($status['proposed_is_closed'] ?? null);
        $categoryKey = $status['jira_status_category_key'] ?? null;
        $notes = $status['notes'] ?? null;

        printf(
            "  - Jira status: %s (ID: %s)%s",
            $jiraName ?? '[missing name]',
            $jiraId,
            PHP_EOL
        );
        printf(
            "    Proposed Redmine name: %s | Should be closed: %s | Jira category: %s%s",
            $proposedName ?? ($jiraName ?? 'n/a'),
            formatBooleanForDisplay($proposedIsClosed),
            $categoryKey ?? 'unknown',
            PHP_EOL
        );
        if ($notes !== null) {
            printf("    Notes: %s%s", $notes, PHP_EOL);
        }
    }

    if (!$confirmPush) {
        printf("  --confirm-push not supplied: mappings remain in READY_FOR_CREATION.%s", PHP_EOL);
        printf("  After creating the statuses manually, update migration_mapping_statuses with the Redmine IDs and set migration_status to CREATION_SUCCESS.%s", PHP_EOL);
        return;
    }

    if ($isDryRun) {
        printf("  --dry-run flag enabled: skipping acknowledgement despite --confirm-push.%s", PHP_EOL);
        return;
    }

    printf("  Manual acknowledgement recorded. Remember to update redmine_status_id and migration_status once the statuses exist in Redmine.%s", PHP_EOL);
}

function fetchStatusesReadyForCreation(PDO $pdo): array
{
    $sql = <<<SQL
        SELECT
            map.mapping_id,
            map.jira_status_id,
            map.jira_status_name,
            map.jira_status_category_key,
            map.proposed_redmine_name,
            map.proposed_is_closed,
            map.notes
        FROM migration_mapping_statuses map
        WHERE map.migration_status = 'READY_FOR_CREATION'
        ORDER BY map.jira_status_name IS NULL, map.jira_status_name, map.jira_status_id
    SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to fetch statuses ready for creation: ' . $exception->getMessage(), 0, $exception);
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
 * @return array{0: array{help: bool, version: bool, phases: ?string, skip: ?string, confirm_push: bool, dry_run: bool, use_extended_api: bool}, 1: array<int, string>}
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
        'use_extended_api' => false,
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
            case '--use-extended-api':
                $options['use_extended_api'] = true;
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

function shouldUseExtendedApi(array $redmineConfig, bool $cliFlag): bool
{
    if ($cliFlag) {
        return true;
    }

    $extendedConfig = isset($redmineConfig['extended_api']) && is_array($redmineConfig['extended_api'])
        ? $redmineConfig['extended_api']
        : [];

    return (bool)($extendedConfig['enabled'] ?? false);
}

function resolveExtendedApiPrefix(array $redmineConfig): string
{
    $extendedConfig = isset($redmineConfig['extended_api']) && is_array($redmineConfig['extended_api'])
        ? $redmineConfig['extended_api']
        : [];

    $prefix = isset($extendedConfig['prefix']) ? trim((string)$extendedConfig['prefix']) : '/extended_api';

    if ($prefix === '') {
        $prefix = '/extended_api';
    }

    return trim($prefix, '/');
}

function buildExtendedApiPath(string $prefix, string $resource): string
{
    $normalizedPrefix = trim($prefix, '/');
    $normalizedResource = ltrim($resource, '/');

    if ($normalizedPrefix === '') {
        return $normalizedResource;
    }

    return $normalizedPrefix . '/' . $normalizedResource;
}

function verifyExtendedApiAvailability(Client $client, string $prefix, string $resource): void
{
    $path = buildExtendedApiPath($prefix, $resource);

    try {
        $response = $client->get($path);
    } catch (BadResponseException $exception) {
        $response = $exception->getResponse();
        $statusCode = $response ? $response->getStatusCode() : 0;
        $reason = $response ? $response->getReasonPhrase() : 'unknown error';
        throw new RuntimeException(
            sprintf('Extended API availability check failed (%s): HTTP %d %s', $path, $statusCode, $reason),
            0,
            $exception
        );
    } catch (GuzzleException $exception) {
        throw new RuntimeException('Failed to reach the extended API: ' . $exception->getMessage(), 0, $exception);
    }

    $header = $response->getHeaderLine('X-Redmine-Extended-API');
    if (trim($header) === '') {
        throw new RuntimeException('Extended API response missing X-Redmine-Extended-API header. Verify the plugin installation.');
    }
}

function summarizeExtendedApiError(Throwable $exception): string
{
    if ($exception instanceof BadResponseException) {
        $response = $exception->getResponse();
        if ($response !== null) {
            $message = sprintf('HTTP %d %s', $response->getStatusCode(), $response->getReasonPhrase());
            $details = extractExtendedApiErrorDetails($response);
            if ($details !== null) {
                $message .= ': ' . $details;
            }

            return $message;
        }
    }

    return $exception->getMessage();
}

function extractExtendedApiErrorDetails(ResponseInterface $response): ?string
{
    $body = trim((string)$response->getBody());
    if ($body === '') {
        return null;
    }

    try {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        return $body !== '' ? $body : null;
    }

    if (is_array($decoded)) {
        if (isset($decoded['errors']) && is_array($decoded['errors'])) {
            return implode('; ', array_map('strval', $decoded['errors']));
        }

        if (isset($decoded['error']) && is_string($decoded['error'])) {
            return $decoded['error'];
        }
    }

    return $body !== '' ? $body : null;
}

function extractCreatedIssueStatusId(mixed $decoded): int
{
    if (is_array($decoded)) {
        if (isset($decoded['issue_status']) && is_array($decoded['issue_status']) && isset($decoded['issue_status']['id'])) {
            return (int)$decoded['issue_status']['id'];
        }

        if (isset($decoded['id'])) {
            return (int)$decoded['id'];
        }
    }

    throw new RuntimeException('Unable to determine the new Redmine status ID from the extended API response.');
}

function printUsage(): void
{
    $scriptName = basename(__FILE__);

    echo sprintf(
        "%s (version %s)%s",
        $scriptName,
        MIGRATE_STATUSES_SCRIPT_VERSION,
        PHP_EOL
    );
    echo sprintf('Usage: php %s [options]%s', $scriptName, PHP_EOL);
    echo PHP_EOL;
    echo "Options:" . PHP_EOL;
    echo "  -h, --help           Display this help message and exit." . PHP_EOL;
    echo "  -V, --version        Print the script version and exit." . PHP_EOL;
    echo "      --phases=LIST    Comma-separated list of phases to execute." . PHP_EOL;
    echo "      --skip=LIST      Comma-separated list of phases to skip." . PHP_EOL;
    echo "      --confirm-push   Mark statuses as acknowledged after manual review." . PHP_EOL;
    echo "      --dry-run        Preview push-phase actions without updating migration status." . PHP_EOL;
    echo "      --use-extended-api  Push new statuses through the redmine_extended_api plugin." . PHP_EOL;
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
    printf('%s version %s%s', basename(__FILE__), MIGRATE_STATUSES_SCRIPT_VERSION, PHP_EOL);
}
