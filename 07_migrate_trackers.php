<?php /** @noinspection PhpArrayIndexImmediatelyRewrittenInspection */
/** @noinspection DuplicatedCode */
/** @noinspection SpellCheckingInspection */

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

const MIGRATE_TRACKERS_SCRIPT_VERSION = '0.0.7';
const AVAILABLE_PHASES = [
    'jira' => 'Extract Jira issue types into staging_jira_issue_types.',
    'redmine' => 'Refresh the Redmine tracker snapshot from the REST API.',
    'transform' => 'Reconcile Jira issue types with Redmine trackers to populate migration mappings.',
    'push' => 'Produce a manual action plan or call the extended API to create missing Redmine trackers.',
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

        printf("[%s] Starting Jira issue type extraction...%s", formatCurrentTimestamp(), PHP_EOL);

        $totalJiraProcessed = fetchAndStoreJiraIssueTypes($jiraClient, $pdo);

        printf(
            "[%s] Completed Jira extraction. %d issue type records processed.%s",
            formatCurrentTimestamp(),
            $totalJiraProcessed,
            PHP_EOL
        );
    } else {
        printf(
            "[%s] Skipping Jira issue type extraction (disabled via CLI option).%s",
            formatCurrentTimestamp(),
            PHP_EOL
        );
    }

    if (in_array('redmine', $phasesToRun, true)) {
        $redmineConfig ??= extractArrayConfig($config, 'redmine');
        $redmineClient = createRedmineClient($redmineConfig);

        printf("[%s] Starting Redmine tracker snapshot...%s", formatCurrentTimestamp(), PHP_EOL);

        $totalRedmineProcessed = fetchAndStoreRedmineTrackers($redmineClient, $pdo);

        printf(
            "[%s] Completed Redmine snapshot. %d tracker records processed.%s",
            formatCurrentTimestamp(),
            $totalRedmineProcessed,
            PHP_EOL
        );
    } else {
        printf(
            "[%s] Skipping Redmine tracker snapshot (disabled via CLI option).%s",
            formatCurrentTimestamp(),
            PHP_EOL
        );
    }

    if (in_array('transform', $phasesToRun, true)) {
        printf("[%s] Starting tracker reconciliation & transform phase...%s", formatCurrentTimestamp(), PHP_EOL);

        $transformSummary = runTrackerTransformationPhase($pdo, $config);

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
            printf("  Current tracker mapping breakdown:%s", PHP_EOL);
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

        runTrackerPushPhase($pdo, $confirmPush, $isDryRun, $redmineConfig, $useExtendedApi);
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
function fetchAndStoreJiraIssueTypes(Client $client, PDO $pdo): int
{
    $insertStatement = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_issue_types (id, name, description, is_subtask, hierarchy_level, scope_type, scope_project_id, raw_payload, extracted_at)
        VALUES (:id, :name, :description, :is_subtask, :hierarchy_level, :scope_type, :scope_project_id, :raw_payload, :extracted_at)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            description = VALUES(description),
            is_subtask = VALUES(is_subtask),
            hierarchy_level = VALUES(hierarchy_level),
            scope_type = VALUES(scope_type),
            scope_project_id = VALUES(scope_project_id),
            raw_payload = VALUES(raw_payload),
            extracted_at = VALUES(extracted_at)
    SQL);

    if ($insertStatement === false) {
        throw new RuntimeException('Failed to prepare insert statement for staging_jira_issue_types.');
    }

    try {
        $response = $client->get('/rest/api/3/issuetype');
    } catch (BadResponseException $exception) {
        $response = $exception->getResponse();
        $message = 'Failed to fetch issue types from Jira';
        $message .= sprintf(' (HTTP %d)', $response->getStatusCode());

        throw new RuntimeException($message, 0, $exception);
    } catch (GuzzleException $exception) {
        throw new RuntimeException('Failed to fetch issue types from Jira: ' . $exception->getMessage(), 0, $exception);
    }

    $decoded = decodeJsonResponse($response);

    if (!is_array($decoded)) {
        throw new RuntimeException('Unexpected response when fetching issue types from Jira.');
    }

    $totalInserted = 0;
    $extractedAt = formatCurrentUtcTimestamp('Y-m-d H:i:s');

    $pdo->beginTransaction();

    try {
        foreach ($decoded as $issueType) {
            if (!is_array($issueType)) {
                continue;
            }

            $issueTypeId = isset($issueType['id']) ? (string)$issueType['id'] : '';
            $name = normalizeString($issueType['name'] ?? null, 255);

            if ($issueTypeId === '' || $name === null) {
                continue;
            }

            $description = $issueType['description'] ?? null;
            if (!is_string($description)) {
                $description = null;
            }

            $isSubtask = normalizeBooleanDatabaseValue($issueType['subtask'] ?? null);
            $hierarchyLevel = normalizeInteger($issueType['hierarchyLevel'] ?? null);

            $scopeType = null;
            $scopeProjectId = null;
            if (isset($issueType['scope']) && is_array($issueType['scope'])) {
                $scopeTypeValue = $issueType['scope']['type'] ?? null;
                if (is_string($scopeTypeValue)) {
                    $scopeType = normalizeString($scopeTypeValue, 50);
                }

                $projectDetails = $issueType['scope']['project'] ?? null;
                if (is_array($projectDetails) && isset($projectDetails['id'])) {
                    $scopeProjectId = normalizeString((string)$projectDetails['id'], 255);
                }
            }

            try {
                $rawPayload = json_encode($issueType, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (JsonException $exception) {
                throw new RuntimeException(
                    sprintf('Failed to encode Jira issue type payload for %s: %s', $issueTypeId, $exception->getMessage()),
                    0,
                    $exception
                );
            }

            $insertStatement->execute([
                'id' => $issueTypeId,
                'name' => $name,
                'description' => $description,
                'is_subtask' => $isSubtask ?? 0,
                'hierarchy_level' => $hierarchyLevel,
                'scope_type' => $scopeType,
                'scope_project_id' => $scopeProjectId,
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

    printf("  Processed %d Jira issue type records.%s", $totalInserted, PHP_EOL);

    return $totalInserted;
}

/**
 * @throws Throwable
 */
function fetchAndStoreRedmineTrackers(Client $client, PDO $pdo): int
{
    try {
        $pdo->exec('TRUNCATE TABLE staging_redmine_trackers');
    } catch (PDOException $exception) {
        throw new RuntimeException('Unable to truncate staging_redmine_trackers: ' . $exception->getMessage(), 0, $exception);
    }

    try {
        $response = $client->get('trackers.json');
    } catch (GuzzleException $exception) {
        throw new RuntimeException('Failed to fetch trackers from Redmine: ' . $exception->getMessage(), 0, $exception);
    }

    $decoded = decodeJsonResponse($response);

    if (!is_array($decoded) || !isset($decoded['trackers']) || !is_array($decoded['trackers'])) {
        throw new RuntimeException('Unexpected response when fetching trackers from Redmine.');
    }

    $insertStatement = $pdo->prepare(<<<SQL
        INSERT INTO staging_redmine_trackers (id, name, description, default_status_id, raw_payload, retrieved_at)
        VALUES (:id, :name, :description, :default_status_id, :raw_payload, :retrieved_at)
    SQL);

    if ($insertStatement === false) {
        throw new RuntimeException('Failed to prepare insert statement for staging_redmine_trackers.');
    }

    $retrievedAt = formatCurrentUtcTimestamp('Y-m-d H:i:s');
    $totalInserted = 0;

    $pdo->beginTransaction();

    try {
        foreach ($decoded['trackers'] as $tracker) {
            if (!is_array($tracker)) {
                continue;
            }

            $trackerId = isset($tracker['id']) ? (int)$tracker['id'] : 0;
            $name = normalizeString($tracker['name'] ?? null, 255);

            if ($trackerId <= 0 || $name === null) {
                continue;
            }

            $description = $tracker['description'] ?? null;
            if (!is_string($description)) {
                $description = null;
            }

            $defaultStatusId = normalizeInteger($tracker['default_status_id'] ?? null, 1);

            try {
                $rawPayload = json_encode($tracker, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (JsonException $exception) {
                throw new RuntimeException(
                    sprintf('Failed to encode Redmine tracker payload for %d: %s', $trackerId, $exception->getMessage()),
                    0,
                    $exception
                );
            }

            $insertStatement->execute([
                'id' => $trackerId,
                'name' => $name,
                'description' => $description,
                'default_status_id' => $defaultStatusId,
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

    printf("  Captured %d Redmine tracker records.%s", $totalInserted, PHP_EOL);

    return $totalInserted;
}

/**
 * @return array{matched: int, ready_for_creation: int, manual_review: int, manual_overrides: int, skipped: int, unchanged: int, status_counts: array<string, int>}
 */

function runTrackerTransformationPhase(PDO $pdo, array $config): array
{
    syncTrackerMappings($pdo);
    refreshTrackerMetadata($pdo);

    $redmineLookup = buildRedmineTrackerLookup($pdo);
    $mappings = fetchTrackerMappingsForTransform($pdo);
    $defaultStatusId = resolveDefaultTrackerStatusId($pdo, $config);

    $updateStatement = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_trackers
        SET
            redmine_tracker_id = :redmine_tracker_id,
            migration_status = :migration_status,
            notes = :notes,
            proposed_redmine_name = :proposed_redmine_name,
            proposed_redmine_description = :proposed_redmine_description,
            proposed_default_status_id = :proposed_default_status_id,
            automation_hash = :automation_hash
        WHERE mapping_id = :mapping_id
    SQL);

    if ($updateStatement === false) {
        throw new RuntimeException('Failed to prepare update statement for migration_mapping_trackers.');
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

        $jiraIssueTypeId = (string)$row['jira_issue_type_id'];
        $jiraIssueTypeName = $row['jira_issue_type_name'] !== null ? (string)$row['jira_issue_type_name'] : null;
        $jiraIssueTypeDescription = $row['jira_issue_type_description'] !== null ? (string)$row['jira_issue_type_description'] : null;
        $jiraIsSubtask = normalizeBooleanFlag($row['jira_is_subtask'] ?? null) ?? false;

        $currentRedmineId = $row['redmine_tracker_id'] !== null ? (int)$row['redmine_tracker_id'] : null;
        $currentNotes = $row['notes'] !== null ? (string)$row['notes'] : null;
        $currentProposedName = $row['proposed_redmine_name'] !== null ? (string)$row['proposed_redmine_name'] : null;
        $currentProposedDescription = $row['proposed_redmine_description'] !== null ? (string)$row['proposed_redmine_description'] : null;
        $currentProposedDefaultStatusId = normalizeInteger($row['proposed_default_status_id'] ?? null, 1);
        $storedAutomationHash = normalizeStoredAutomationHash($row['automation_hash'] ?? null);

        $currentAutomationHash = computeTrackerAutomationStateHash(
            $currentRedmineId,
            $currentStatus,
            $currentProposedName,
            $currentProposedDescription,
            $currentProposedDefaultStatusId,
            $currentNotes
        );

        if ($storedAutomationHash !== null && $storedAutomationHash !== $currentAutomationHash) {
            $summary['manual_overrides']++;
            printf(
                "  [preserved] Jira issue type %s has manual overrides; skipping automated changes.%s",
                $jiraIssueTypeName ?? $jiraIssueTypeId,
                PHP_EOL
            );
            continue;
        }

        $defaultName = $jiraIssueTypeName !== null ? normalizeString($jiraIssueTypeName, 255) : null;
        $defaultDescription = $jiraIssueTypeDescription !== null ? trim($jiraIssueTypeDescription) : null;

        $manualReason = null;
        $newStatus = $currentStatus;
        $newRedmineId = $currentRedmineId;
        $proposedName = $currentProposedName ?? $defaultName;
        $proposedDescription = $currentProposedDescription ?? $defaultDescription;
        $proposedDefaultStatusId = $currentProposedDefaultStatusId ?? $defaultStatusId;

        if ($defaultName === null) {
            $manualReason = 'Missing Jira issue type name in the staging snapshot.';
        } else {
            $lookupKey = strtolower($defaultName);
            $matchedRedmine = $redmineLookup[$lookupKey] ?? null;

            if ($matchedRedmine !== null) {
                $newStatus = 'MATCH_FOUND';
                $newRedmineId = (int)$matchedRedmine['id'];
                $proposedName = normalizeString($matchedRedmine['name'] ?? $defaultName, 255) ?? $defaultName;
                $matchedDescription = $matchedRedmine['description'] ?? null;
                $proposedDescription = is_string($matchedDescription) ? trim($matchedDescription) : null;
                $matchedDefaultStatusId = normalizeInteger($matchedRedmine['default_status_id'] ?? null, 1);
                $proposedDefaultStatusId = $matchedDefaultStatusId;
            } else {
                $newStatus = 'READY_FOR_CREATION';
                $newRedmineId = null;
                $proposedName = $defaultName;
                if ($proposedDescription === null) {
                    $proposedDescription = $defaultDescription;
                }

                if ($currentProposedDefaultStatusId !== null) {
                    $proposedDefaultStatusId = $currentProposedDefaultStatusId;
                } elseif ($defaultStatusId !== null) {
                    $proposedDefaultStatusId = $defaultStatusId;
                } else {
                    $proposedDefaultStatusId = null;
                }
            }
        }

        if ($manualReason === null && $newStatus === 'READY_FOR_CREATION' && $proposedDefaultStatusId === null) {
            $manualReason = 'Unable to determine a default Redmine status for the tracker.';
        }

        if ($manualReason !== null) {
            $newStatus = 'MANUAL_INTERVENTION_REQUIRED';
            $newRedmineId = null;
            if ($proposedName === null) {
                $proposedName = $defaultName ?? $jiraIssueTypeId;
            }

            $notes = $manualReason;
            if ($jiraIsSubtask) {
                $notes .= ' (Jira issue type is a sub-task.)';
            }

            printf(
                "  [manual] Jira issue type %s: %s%s",
                $defaultName ?? $jiraIssueTypeId,
                $manualReason,
                PHP_EOL
            );
        } else {
            $notes = null;
            if ($jiraIsSubtask && $newStatus === 'READY_FOR_CREATION') {
                $notes = 'Jira issue type is a sub-task; confirm whether a separate Redmine tracker is required.';
            }
        }

        if ($proposedName === null) {
            $proposedName = $defaultName ?? $jiraIssueTypeId;
        }

        $newAutomationHash = computeTrackerAutomationStateHash(
            $newRedmineId,
            $newStatus,
            $proposedName,
            $proposedDescription,
            $proposedDefaultStatusId,
            $notes
        );

        $needsUpdate = $currentRedmineId !== $newRedmineId
            || $currentStatus !== $newStatus
            || $currentProposedName !== $proposedName
            || $currentProposedDescription !== $proposedDescription
            || $currentProposedDefaultStatusId !== $proposedDefaultStatusId
            || $currentNotes !== $notes
            || $storedAutomationHash !== $newAutomationHash;

        if (!$needsUpdate) {
            $summary['unchanged']++;
            continue;
        }

        $updateStatement->execute([
            'redmine_tracker_id' => $newRedmineId,
            'migration_status' => $newStatus,
            'notes' => $notes,
            'proposed_redmine_name' => $proposedName,
            'proposed_redmine_description' => $proposedDescription,
            'proposed_default_status_id' => $proposedDefaultStatusId,
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

    $summary['status_counts'] = fetchTrackerMigrationStatusCounts($pdo);

    return $summary;
}


function syncTrackerMappings(PDO $pdo): void
{
    $sql = <<<SQL
        INSERT INTO migration_mapping_trackers (jira_issue_type_id, migration_status, notes, created_at, last_updated_at)
        SELECT jit.id, 'PENDING_ANALYSIS', NULL, NOW(), NOW()
        FROM staging_jira_issue_types jit
        ON DUPLICATE KEY UPDATE last_updated_at = VALUES(last_updated_at)
    SQL;

    try {
        $pdo->exec($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to synchronise migration_mapping_trackers: ' . $exception->getMessage(), 0, $exception);
    }
}


function refreshTrackerMetadata(PDO $pdo): void
{
    $sql = <<<SQL
        UPDATE migration_mapping_trackers map
        INNER JOIN staging_jira_issue_types jit ON jit.id = map.jira_issue_type_id
        SET
            map.jira_issue_type_name = jit.name,
            map.jira_issue_type_description = jit.description,
            map.jira_is_subtask = jit.is_subtask,
            map.jira_hierarchy_level = jit.hierarchy_level,
            map.jira_scope_type = jit.scope_type,
            map.jira_scope_project_id = jit.scope_project_id
        WHERE 1
    SQL;

    try {
        $pdo->exec($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to refresh Jira issue type metadata in migration_mapping_trackers: ' . $exception->getMessage(), 0, $exception);
    }
}

/**
/**
 * @return array<string, array{id: int, name: string, is_default: mixed}>
 */



function buildRedmineTrackerLookup(PDO $pdo): array
{
    try {
        $statement = $pdo->query('SELECT id, name, description, default_status_id FROM staging_redmine_trackers');
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to build Redmine tracker lookup: ' . $exception->getMessage(), 0, $exception);
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
function fetchTrackerMappingsForTransform(PDO $pdo): array
{
    $sql = <<<SQL
        SELECT
            map.mapping_id,
            map.jira_issue_type_id,
            map.jira_issue_type_name,
            map.jira_issue_type_description,
            map.jira_is_subtask,
            map.jira_hierarchy_level,
            map.jira_scope_type,
            map.jira_scope_project_id,
            map.redmine_tracker_id,
            map.migration_status,
            map.notes,
            map.proposed_redmine_name,
            map.proposed_redmine_description,
            map.proposed_default_status_id,
            map.automation_hash
        FROM migration_mapping_trackers map
        ORDER BY map.jira_issue_type_name IS NULL, map.jira_issue_type_name, map.jira_issue_type_id
    SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to fetch migration mapping rows for trackers: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function computeTrackerAutomationStateHash(
    ?int $redmineTrackerId,
    string $migrationStatus,
    ?string $proposedName,
    ?string $proposedDescription,
    ?int $proposedDefaultStatusId,
    ?string $notes
): string
{
    $payload = [
        'redmine_tracker_id' => $redmineTrackerId,
        'migration_status' => $migrationStatus,
        'proposed_redmine_name' => $proposedName,
        'proposed_redmine_description' => $proposedDescription,
        'proposed_default_status_id' => $proposedDefaultStatusId,
        'notes' => $notes,
    ];

    try {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (JsonException $exception) {
        throw new RuntimeException('Failed to compute automation hash for tracker mapping: ' . $exception->getMessage(), 0, $exception);
    }

    return hash('sha256', $json);
}

function fetchTrackerMigrationStatusCounts(PDO $pdo): array
{
    try {
        $statement = $pdo->query('SELECT migration_status, COUNT(*) AS total FROM migration_mapping_trackers GROUP BY migration_status');
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to compute tracker migration breakdown: ' . $exception->getMessage(), 0, $exception);
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



function resolveDefaultTrackerStatusId(PDO $pdo, array $config): ?int
{
    $configured = null;

    if (isset($config['migration']) && is_array($config['migration'])) {
        $migrationConfig = $config['migration'];
        if (isset($migrationConfig['trackers']) && is_array($migrationConfig['trackers'])) {
            $trackerConfig = $migrationConfig['trackers'];
            if (isset($trackerConfig['default_redmine_status_id'])) {
                $configuredValue = $trackerConfig['default_redmine_status_id'];
                if ($configuredValue !== null && $configuredValue !== '') {
                    $configured = normalizeInteger($configuredValue, 1);
                }
            }
        }
    }

    if ($configured !== null) {
        return $configured;
    }

    try {
        $statement = $pdo->query('SELECT id FROM staging_redmine_issue_statuses WHERE is_closed = 0 ORDER BY id LIMIT 1');
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to determine a default Redmine status for trackers: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement !== false) {
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row !== false && isset($row['id'])) {
            $openStatusId = normalizeInteger($row['id'], 1);
            if ($openStatusId !== null) {
                return $openStatusId;
            }
        }
    }

    try {
        $statement = $pdo->query('SELECT id FROM staging_redmine_issue_statuses ORDER BY id LIMIT 1');
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to inspect Redmine statuses for tracker defaults: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement !== false) {
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row !== false && isset($row['id'])) {
            $anyStatusId = normalizeInteger($row['id'], 1);
            if ($anyStatusId !== null) {
                return $anyStatusId;
            }
        }
    }

    return null;
}

function runTrackerPushPhase(PDO $pdo, bool $confirmPush, bool $isDryRun, array $redmineConfig, bool $useExtendedApi): void
{
    $pendingTrackers = fetchTrackersReadyForCreation($pdo);
    $pendingCount = count($pendingTrackers);

    if ($useExtendedApi) {
        printf("[%s] Starting push phase (Redmine extended API)...%s", formatCurrentTimestamp(), PHP_EOL);

        if ($pendingCount === 0) {
            printf("  No Jira issue types are marked as READY_FOR_CREATION.%s", PHP_EOL);
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
        verifyExtendedApiAvailability($redmineClient, $extendedApiPrefix, 'trackers.json');

        $endpoint = buildExtendedApiPath($extendedApiPrefix, 'trackers.json');

        printf("  %d tracker(s) queued for creation via the extended API.%s", $pendingCount, PHP_EOL);
        foreach ($pendingTrackers as $tracker) {
            $jiraName = $tracker['jira_issue_type_name'] ?? null;
            $jiraId = (string)$tracker['jira_issue_type_id'];
            $proposedName = $tracker['proposed_redmine_name'] ?? null;
            $proposedDescription = $tracker['proposed_redmine_description'] ?? null;
            $proposedDefaultStatusId = normalizeInteger($tracker['proposed_default_status_id'] ?? null, 1);
            $notes = $tracker['notes'] ?? null;

            $effectiveName = $proposedName ?? ($jiraName ?? $jiraId);
            $effectiveDescription = $proposedDescription !== null ? trim((string)$proposedDescription) : null;
            if ($effectiveDescription === '') {
                $effectiveDescription = null;
            }
            $effectiveDefaultStatusId = $proposedDefaultStatusId;

            printf(
                '  - Jira issue type %s (ID: %s) -> Redmine "%s" (default status ID: %s).%s',
                $jiraName ?? '[missing name]',
                $jiraId,
                $effectiveName,
                $effectiveDefaultStatusId !== null ? (string)$effectiveDefaultStatusId : 'n/a',
                PHP_EOL
            );
            if ($effectiveDescription !== null) {
                printf("    Description: %s%s", $effectiveDescription, PHP_EOL);
            }
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
            UPDATE migration_mapping_trackers
            SET
                redmine_tracker_id = :redmine_tracker_id,
                migration_status = :migration_status,
                notes = :notes,
                proposed_redmine_name = :proposed_redmine_name,
                proposed_redmine_description = :proposed_redmine_description,
                proposed_default_status_id = :proposed_default_status_id,
                automation_hash = :automation_hash
            WHERE mapping_id = :mapping_id
        SQL);

        if ($updateStatement === false) {
            throw new RuntimeException('Failed to prepare update statement for migration_mapping_trackers during the push phase.');
        }

        $successCount = 0;
        $failureCount = 0;

        foreach ($pendingTrackers as $tracker) {
            $mappingId = (int)$tracker['mapping_id'];
            $jiraId = (string)$tracker['jira_issue_type_id'];
            $jiraName = $tracker['jira_issue_type_name'] ?? null;
            $proposedName = $tracker['proposed_redmine_name'] ?? null;
            $proposedDescription = $tracker['proposed_redmine_description'] ?? null;
            $proposedDefaultStatusId = normalizeInteger($tracker['proposed_default_status_id'] ?? null, 1);
            $notes = $tracker['notes'] ?? null;

            $effectiveName = $proposedName ?? ($jiraName ?? $jiraId);
            $effectiveDescription = $proposedDescription !== null ? trim((string)$proposedDescription) : null;
            if ($effectiveDescription === '') {
                $effectiveDescription = null;
            }
            $effectiveDefaultStatusId = $proposedDefaultStatusId;

            $payload = [
                'tracker' => [
                    'name' => $effectiveName,
                ],
            ];

            if ($effectiveDescription !== null) {
                $payload['tracker']['description'] = $effectiveDescription;
            }

            if ($effectiveDefaultStatusId !== null) {
                $payload['tracker']['default_status_id'] = $effectiveDefaultStatusId;
            }

            try {
                $response = $redmineClient->post($endpoint, ['json' => $payload]);
                $decoded = decodeJsonResponse($response);
                $newTrackerId = extractCreatedTrackerId($decoded);

                $automationHash = computeTrackerAutomationStateHash(
                    $newTrackerId,
                    'CREATION_SUCCESS',
                    $effectiveName,
                    $effectiveDescription,
                    $effectiveDefaultStatusId,
                    null
                );

                $updateStatement->execute([
                    'redmine_tracker_id' => $newTrackerId,
                    'migration_status' => 'CREATION_SUCCESS',
                    'notes' => null,
                    'proposed_redmine_name' => $effectiveName,
                    'proposed_redmine_description' => $effectiveDescription,
                    'proposed_default_status_id' => $effectiveDefaultStatusId,
                    'automation_hash' => $automationHash,
                    'mapping_id' => $mappingId,
                ]);

                printf(
                    '  [created] Jira issue type %s (%s) -> Redmine tracker #%d.%s',
                    $jiraName ?? $jiraId,
                    $jiraId,
                    $newTrackerId,
                    PHP_EOL
                );

                $successCount++;
            } catch (Throwable $exception) {
                $errorMessage = summarizeExtendedApiError($exception);
                $automationHash = computeTrackerAutomationStateHash(
                    null,
                    'CREATION_FAILED',
                    $effectiveName,
                    $effectiveDescription,
                    $effectiveDefaultStatusId,
                    $errorMessage
                );

                $updateStatement->execute([
                    'redmine_tracker_id' => null,
                    'migration_status' => 'CREATION_FAILED',
                    'notes' => $errorMessage,
                    'proposed_redmine_name' => $effectiveName,
                    'proposed_redmine_description' => $effectiveDescription,
                    'proposed_default_status_id' => $effectiveDefaultStatusId,
                    'automation_hash' => $automationHash,
                    'mapping_id' => $mappingId,
                ]);

                printf(
                    '  [failed] Jira issue type %s (%s): %s%s',
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

    printf('[%s] Starting push phase (manual tracker checklist)...%s', formatCurrentTimestamp(), PHP_EOL);

    if ($pendingCount === 0) {
        printf("  No Jira issue types are marked as READY_FOR_CREATION.%s", PHP_EOL);
        if ($isDryRun) {
            printf("  --dry-run flag enabled: no database changes will be made.%s", PHP_EOL);
        }
        if ($confirmPush) {
            printf("  --confirm-push provided but there is nothing to acknowledge.%s", PHP_EOL);
        } else {
            printf("  Provide --confirm-push after manually creating any outstanding trackers in Redmine.%s", PHP_EOL);
        }
        return;
    }

    printf("  %d tracker(s) require manual creation in Redmine.%s", $pendingCount, PHP_EOL);
    foreach ($pendingTrackers as $tracker) {
        $jiraName = $tracker['jira_issue_type_name'] ?? null;
        $jiraId = (string)$tracker['jira_issue_type_id'];
        $proposedName = $tracker['proposed_redmine_name'] ?? null;
        $proposedDescription = $tracker['proposed_redmine_description'] ?? null;
        $proposedDefaultStatusId = normalizeInteger($tracker['proposed_default_status_id'] ?? null, 1);
        $notes = $tracker['notes'] ?? null;

        printf(
            "  - Jira issue type: %s (ID: %s)%s",
            $jiraName ?? '[missing name]',
            $jiraId,
            PHP_EOL
        );
        printf(
            "    Proposed Redmine name: %s | Default status ID: %s%s",
            $proposedName ?? ($jiraName ?? 'n/a'),
            $proposedDefaultStatusId !== null ? (string)$proposedDefaultStatusId : 'n/a',
            PHP_EOL
        );
        if ($proposedDescription !== null && trim((string)$proposedDescription) !== '') {
            printf("    Proposed description: %s%s", trim((string)$proposedDescription), PHP_EOL);
        }
        if ($notes !== null) {
            printf("    Notes: %s%s", $notes, PHP_EOL);
        }
    }

    if (!$confirmPush) {
        printf("  --confirm-push not supplied: mappings remain in READY_FOR_CREATION.%s", PHP_EOL);
        printf("  After creating the trackers manually, update migration_mapping_trackers with the Redmine IDs and set migration_status to CREATION_SUCCESS.%s", PHP_EOL);
        return;
    }

    if ($isDryRun) {
        printf("  --dry-run flag enabled: skipping acknowledgement despite --confirm-push.%s", PHP_EOL);
        return;
    }

    printf("  Manual acknowledgement recorded. Remember to update redmine_tracker_id and migration_status once the trackers exist in Redmine.%s", PHP_EOL);
}


function fetchTrackersReadyForCreation(PDO $pdo): array
{
    $sql = <<<SQL
        SELECT
            map.mapping_id,
            map.jira_issue_type_id,
            map.jira_issue_type_name,
            map.jira_issue_type_description,
            map.jira_is_subtask,
            map.proposed_redmine_name,
            map.proposed_redmine_description,
            map.proposed_default_status_id,
            map.notes
        FROM migration_mapping_trackers map
        WHERE map.migration_status = 'READY_FOR_CREATION'
        ORDER BY
            map.proposed_redmine_name IS NULL,
            map.proposed_redmine_name,
            map.jira_issue_type_name IS NULL,
            map.jira_issue_type_name,
            map.jira_issue_type_id
    SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to fetch trackers ready for creation: ' . $exception->getMessage(), 0, $exception);
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

function formatIntegerForDisplay(?int $value): string
{
    if ($value === null) {
        return 'n/a';
    }

    return (string)$value;
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

function normalizeInteger(mixed $value, int $min = PHP_INT_MIN, ?int $max = null): ?int
{
    if ($value === null) {
        return null;
    }

    if (is_int($value)) {
        $intValue = $value;
    } elseif (is_float($value)) {
        if (!is_finite($value) || floor($value) !== $value) {
            return null;
        }

        $intValue = (int)$value;
    } elseif (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '' || !preg_match('/^-?\d+$/', $trimmed)) {
            return null;
        }

        $intValue = (int)$trimmed;
    } else {
        return null;
    }

    if ($intValue < $min) {
        return null;
    }

    if ($max !== null && $intValue > $max) {
        return null;
    }

    return $intValue;
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


function extractCreatedTrackerId(mixed $decoded): int
{
    if (is_array($decoded)) {
        if (isset($decoded['tracker']) && is_array($decoded['tracker']) && isset($decoded['tracker']['id'])) {
            return (int)$decoded['tracker']['id'];
        }

        if (isset($decoded['id'])) {
            return (int)$decoded['id'];
        }
    }

    throw new RuntimeException('Unable to determine the new Redmine tracker ID from the extended API response.');
}

function printUsage(): void
{
    $scriptName = basename(__FILE__);

    echo sprintf(
        "%s (version %s)%s",
        $scriptName,
        MIGRATE_TRACKERS_SCRIPT_VERSION,
        PHP_EOL
    );
    echo sprintf('Usage: php %s [options]%s', $scriptName, PHP_EOL);
    echo PHP_EOL;
    echo "Options:" . PHP_EOL;
    echo "  -h, --help           Display this help message and exit." . PHP_EOL;
    echo "  -V, --version        Print the script version and exit." . PHP_EOL;
    echo "      --phases=LIST    Comma-separated list of phases to execute." . PHP_EOL;
    echo "      --skip=LIST      Comma-separated list of phases to skip." . PHP_EOL;
    echo "      --confirm-push   Mark trackers as acknowledged after manual review." . PHP_EOL;
    echo "      --dry-run        Preview push-phase actions without updating migration status." . PHP_EOL;
    echo "      --use-extended-api  Push new trackers through the redmine_extended_api plugin." . PHP_EOL;
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
    printf('%s version %s%s', basename(__FILE__), MIGRATE_TRACKERS_SCRIPT_VERSION, PHP_EOL);
}
