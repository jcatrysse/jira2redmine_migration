<?php

/**
 * @noinspection PhpArrayIndexImmediatelyRewrittenInspection
 * @noinspection DuplicatedCode
 * @noinspection SpellCheckingInspection
 */

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Jira2Redmine\Http\ClientFactory;
use Jira2Redmine\Database\ConnectionFactory;
use Jira2Redmine\Redmine\ExtendedApi;
use Jira2Redmine\Cli\Phases;
use Jira2Redmine\Priorities\PriorityProposal;
use Jira2Redmine\Cli\Script;
use Jira2Redmine\Cli\ScriptCommandLines;
use Jira2Redmine\Cli\ConfigValue;
use Jira2Redmine\Time\Clock;

const MIGRATE_PRIORITIES_SCRIPT_VERSION = '0.0.1';
const AVAILABLE_PHASES = [
    'jira' => 'Extract Jira issue priorities into staging_jira_priorities.',
    'redmine' => 'Refresh the Redmine issue priority snapshot from the REST API.',
    'transform' => 'Reconcile Jira and Redmine priorities to populate migration mappings.',
    'push' => 'Produce a manual action plan or call the extended API to create missing Redmine priorities.',
];

require_once __DIR__ . '/lib/cli.php';

exit(Script::run(
    $_SERVER['argv'] ?? ($GLOBALS['argv'] ?? null),
    ScriptCommandLines::for(basename(__FILE__)),
    'printUsage',
    'printVersion',
    static function (array $cliOptions): void {
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/bootstrap.php';
        /**
         * @var array{
         *     help: bool, version: bool, phases: ?string, skip: ?string, confirm_push: bool, dry_run: bool, use_extended_api: bool
         * } $cliOptions
         */
        main($config, $cliOptions);
    }
));

/**
 * @param array<string, mixed> $config
 * @param array{help: bool, version: bool, phases: ?string, skip: ?string, confirm_push: bool, dry_run: bool, use_extended_api: bool} $cliOptions
 * @throws Throwable
 */
function main(array $config, array $cliOptions): void
{
    $phasesToRun = Phases::fromOptions(AVAILABLE_PHASES, $cliOptions);

    printf(
        "[%s] Selected phases: %s%s",
        Clock::atom(),
        implode(', ', $phasesToRun),
        PHP_EOL
    );

    $databaseConfig = ConfigValue::section($config, 'database');
    $pdo = ConnectionFactory::connect($databaseConfig);

    /** @var array<string, mixed>|null $redmineConfig */
    $redmineConfig = null;

    if (in_array('jira', $phasesToRun, true)) {
        $jiraConfig = ConfigValue::section($config, 'jira');
        $jiraClient = ClientFactory::jira($jiraConfig, 30.0);

        printf("[%s] Starting Jira priority extraction...%s", Clock::atom(), PHP_EOL);

        $totalJiraProcessed = fetchAndStoreJiraIssuePriorities($jiraClient, $pdo);

        printf(
            "[%s] Completed Jira extraction. %d priority records processed.%s",
            Clock::atom(),
            $totalJiraProcessed,
            PHP_EOL
        );
    } else {
        printf(
            "[%s] Skipping Jira priority extraction (disabled via CLI option).%s",
            Clock::atom(),
            PHP_EOL
        );
    }

    if (in_array('redmine', $phasesToRun, true)) {
        $redmineConfig ??= ConfigValue::section($config, 'redmine');
        $redmineClient = ClientFactory::redmine($redmineConfig, 30.0);

        printf("[%s] Starting Redmine priority snapshot...%s", Clock::atom(), PHP_EOL);

        $totalRedmineProcessed = fetchAndStoreRedmineIssuePriorities($redmineClient, $pdo);

        printf(
            "[%s] Completed Redmine snapshot. %d priority records processed.%s",
            Clock::atom(),
            $totalRedmineProcessed,
            PHP_EOL
        );
    } else {
        printf(
            "[%s] Skipping Redmine priority snapshot (disabled via CLI option).%s",
            Clock::atom(),
            PHP_EOL
        );
    }

    if (in_array('transform', $phasesToRun, true)) {
        printf("[%s] Starting priority reconciliation & transform phase...%s", Clock::atom(), PHP_EOL);

        $transformSummary = runPriorityTransformationPhase($pdo, (array)($config['migration']['priorities'] ?? []));

        printf(
            "[%s] Completed transform phase. Matched: %d, Ready: %d, Manual: %d, Overrides kept: %d, Skipped: %d, Unchanged: %d.%s",
            Clock::atom(),
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
            Clock::atom(),
            PHP_EOL
        );
    }

    if (in_array('push', $phasesToRun, true)) {
        $confirmPush = $cliOptions['confirm_push'];
        $isDryRun = $cliOptions['dry_run'];
        $redmineConfig ??= ConfigValue::section($config, 'redmine');
        $useExtendedApi = ExtendedApi::enabled($redmineConfig, $cliOptions['use_extended_api']);

        runPriorityPushPhase($pdo, $confirmPush, $isDryRun, $redmineConfig, $useExtendedApi);
    } else {
        printf(
            "[%s] Skipping push phase (disabled via CLI option).%s",
            Clock::atom(),
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
    $extractedAt = Clock::dateTime();

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
        INSERT INTO staging_redmine_issue_priorities (id, name, is_default, position, raw_payload, retrieved_at)
        VALUES (:id, :name, :is_default, :position, :raw_payload, :retrieved_at)
    SQL);

    if ($insertStatement === false) {
        throw new RuntimeException('Failed to prepare insert statement for staging_redmine_issue_priorities.');
    }

    $retrievedAt = Clock::dateTime();
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

            $position = normalizeInteger($priority['position'] ?? null, 1);

            $insertStatement->execute([
                'id' => $priorityId,
                'name' => $name,
                'is_default' => $isDefaultValue,
                'position' => $position,
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
 * @param array<string, mixed> $priorityConfig
 * @return array{matched: int, ready_for_creation: int, manual_review: int, manual_overrides: int, skipped: int, unchanged: int, status_counts: array<string, int>}
 */
function runPriorityTransformationPhase(PDO $pdo, array $priorityConfig = []): array
{
    syncPriorityMappings($pdo);
    refreshPriorityMetadata($pdo);

    $redmineByName = [];
    foreach (buildRedminePriorityLookup($pdo) as $key => $details) {
        $redmineByName[$key] = [
            'id' => (int)$details['id'],
            'name' => (string)normalizeString($details['name'], 255),
            'is_default' => normalizeBooleanFlag($details['is_default'] ?? null),
            'position' => isset($details['position']) ? normalizeInteger($details['position'], 1) : null,
        ];
    }
    $proposals = new PriorityProposal(
        $redmineByName,
        is_array($priorityConfig['mappings'] ?? null) ? $priorityConfig['mappings'] : [],
        (bool)($priorityConfig['create_missing'] ?? false)
    );
    $mappings = fetchPriorityMappingsForTransform($pdo);

    $updateStatement = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_priorities
        SET
            redmine_priority_id = :redmine_priority_id,
            migration_status = :migration_status,
            notes = :notes,
            proposed_redmine_name = :proposed_redmine_name,
            proposed_is_default = :proposed_is_default,
            proposed_redmine_position = :proposed_redmine_position,
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
        // MANUAL too: a mapping added to the configuration later settles the row (a hand edit is kept by its hash)
        $allowedStatuses = ['PENDING_ANALYSIS', 'READY_FOR_CREATION', 'MATCH_FOUND', 'CREATION_FAILED', 'MANUAL_INTERVENTION_REQUIRED'];
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
        $currentProposedPosition = normalizeInteger($row['proposed_redmine_position'] ?? null, 1);
        $storedAutomationHash = normalizeStoredAutomationHash($row['automation_hash'] ?? null);

        $currentAutomationHash = computePriorityAutomationStateHash(
            $currentRedmineId,
            $currentStatus,
            $currentProposedName,
            $currentProposedIsDefault,
            $currentProposedPosition,
            $currentNotes
        );

        $legacyAutomationHash = computeLegacyPriorityAutomationStateHash(
            $currentRedmineId,
            $currentStatus,
            $currentProposedName,
            $currentProposedIsDefault,
            $currentNotes
        );

        if (
            $storedAutomationHash !== null
            && $storedAutomationHash !== $currentAutomationHash
            && $storedAutomationHash !== $legacyAutomationHash
        ) {
            $summary['manual_overrides']++;
            printf(
                "  [preserved] Jira priority %s has manual overrides; skipping automated changes.%s",
                $jiraPriorityName ?? $jiraPriorityId,
                PHP_EOL
            );
            continue;
        }

        $defaultName = $jiraPriorityName !== null ? normalizeString($jiraPriorityName, 255) : null;
        $proposal = $proposals->propose($jiraPriorityId, $defaultName, $currentProposedName, $currentProposedIsDefault, $currentProposedPosition);
        $newStatus = $proposal['status'];
        $newRedmineId = $proposal['redmine_id'];
        $proposedName = $proposal['name'];
        $proposedIsDefault = $proposal['is_default'];
        $proposedPosition = $proposal['position'];
        $notes = $proposal['notes'];
        if ($newStatus === 'MANUAL_INTERVENTION_REQUIRED') {
            printf(
                "  [manual] Jira priority %s: %s%s",
                $defaultName ?? $jiraPriorityId,
                $notes,
                PHP_EOL
            );
        }

        $newAutomationHash = computePriorityAutomationStateHash(
            $newRedmineId,
            $newStatus,
            $proposedName,
            $proposedIsDefault,
            $proposedPosition,
            $notes
        );

        $needsUpdate = $currentRedmineId !== $newRedmineId
            || $currentStatus !== $newStatus
            || $currentProposedName !== $proposedName
            || $currentProposedIsDefault !== $proposedIsDefault
            || $currentProposedPosition !== $proposedPosition
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
            'proposed_redmine_position' => $proposedPosition,
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
 * @return array<string, array<string, mixed>> the staging rows by lowercase name
 */
function buildRedminePriorityLookup(PDO $pdo): array
{
    try {
        $statement = $pdo->query('SELECT id, name, is_default, position FROM staging_redmine_issue_priorities');
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
            map.proposed_redmine_position,
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

function computePriorityAutomationStateHash(
    ?int $redminePriorityId,
    string $migrationStatus,
    ?string $proposedName,
    ?bool $proposedIsDefault,
    ?int $proposedPosition,
    ?string $notes
): string {
    $payload = [
        'redmine_priority_id' => $redminePriorityId,
        'migration_status' => $migrationStatus,
        'proposed_redmine_name' => $proposedName,
        'proposed_is_default' => $proposedIsDefault,
        'proposed_redmine_position' => $proposedPosition,
        'notes' => $notes,
    ];

    try {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (JsonException $exception) {
        throw new RuntimeException('Failed to compute automation hash for priority mapping: ' . $exception->getMessage(), 0, $exception);
    }

    return hash('sha256', $json);
}

function computeLegacyPriorityAutomationStateHash(
    ?int $redminePriorityId,
    string $migrationStatus,
    ?string $proposedName,
    ?bool $proposedIsDefault,
    ?string $notes
): string {
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
        throw new RuntimeException('Failed to compute legacy automation hash for priority mapping: ' . $exception->getMessage(), 0, $exception);
    }

    return hash('sha256', $json);
}

/**
 * @return array<string, int>
 */
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

/**
 * @param array<string, mixed> $redmineConfig
 */
function runPriorityPushPhase(PDO $pdo, bool $confirmPush, bool $isDryRun, array $redmineConfig, bool $useExtendedApi): void
{
    $pendingPriorities = fetchPrioritiesReadyForCreation($pdo);
    $pendingCount = count($pendingPriorities);

    if ($useExtendedApi) {
        printf("[%s] Starting push phase (Redmine extended API)...%s", Clock::atom(), PHP_EOL);

        if ($pendingCount === 0) {
            printf("  No Jira priorities are marked as READY_FOR_CREATION.%s", PHP_EOL);
            if ($isDryRun) {
                printf("  --dry-run flag enabled: no API calls will be made.%s", PHP_EOL);
            }
            if ($confirmPush) {
                printf("  --confirm-push provided but there is nothing to process.%s", PHP_EOL);
            }
            return;
        }

        $redmineClient = ClientFactory::redmine($redmineConfig, 30.0);
        $extendedApiPrefix = ExtendedApi::prefix($redmineConfig);
        ExtendedApi::verifyAvailable(
            $redmineClient,
            $extendedApiPrefix,
            'enumerations.json?type=issue_priorities'
        );

        $endpoint = ExtendedApi::path($extendedApiPrefix, 'enumerations.json');

        printf("  %d priority/priorities queued for creation via the extended API.%s", $pendingCount, PHP_EOL);
        foreach ($pendingPriorities as $priority) {
            $jiraName = $priority['jira_priority_name'] ?? null;
            $jiraId = (string)$priority['jira_priority_id'];
            $proposedName = $priority['proposed_redmine_name'] ?? null;
            $proposedIsDefault = normalizeBooleanFlag($priority['proposed_is_default'] ?? null);
            $proposedPosition = normalizeInteger($priority['proposed_redmine_position'] ?? null, 1);
            $notes = $priority['notes'] ?? null;

            $effectiveName = $proposedName ?? ($jiraName ?? $jiraId);
            $effectiveIsDefault = $proposedIsDefault ?? false;
            $effectivePosition = $proposedPosition;

            printf(
                "  - Jira priority %s (ID: %s) -> Redmine \"%s\" (default: %s, position: %s).%s",
                $jiraName ?? '[missing name]',
                $jiraId,
                $effectiveName,
                formatBooleanForDisplay($effectiveIsDefault),
                formatIntegerForDisplay($effectivePosition),
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
            UPDATE migration_mapping_priorities
            SET
                redmine_priority_id = :redmine_priority_id,
                migration_status = :migration_status,
                notes = :notes,
                proposed_redmine_name = :proposed_redmine_name,
                proposed_is_default = :proposed_is_default,
                proposed_redmine_position = :proposed_redmine_position,
                automation_hash = :automation_hash
            WHERE mapping_id = :mapping_id
        SQL);

        if ($updateStatement === false) {
            throw new RuntimeException('Failed to prepare update statement for migration_mapping_priorities during the push phase.');
        }

        $successCount = 0;
        $failureCount = 0;

        foreach ($pendingPriorities as $priority) {
            $mappingId = (int)$priority['mapping_id'];
            $jiraId = (string)$priority['jira_priority_id'];
            $jiraName = $priority['jira_priority_name'] ?? null;
            $proposedName = $priority['proposed_redmine_name'] ?? null;
            $proposedIsDefault = normalizeBooleanFlag($priority['proposed_is_default'] ?? null) ?? false;
            $proposedPosition = normalizeInteger($priority['proposed_redmine_position'] ?? null, 1);
            $notes = $priority['notes'] ?? null;

            $effectiveName = $proposedName ?? ($jiraName ?? $jiraId);
            $effectivePosition = $proposedPosition;

            $payload = [
                'enumeration' => [
                    'name' => $effectiveName,
                    'is_default' => $proposedIsDefault,
                    'active' => true,
                    'type' => 'IssuePriority',
                ],
            ];

            if ($effectivePosition !== null) {
                $payload['enumeration']['position'] = $effectivePosition;
            }

            try {
                $response = $redmineClient->post($endpoint, ['json' => $payload]);
                $decoded = decodeJsonResponse($response);
                $newPriorityId = extractCreatedPriorityId($decoded);

                $automationHash = computePriorityAutomationStateHash(
                    $newPriorityId,
                    'CREATION_SUCCESS',
                    $effectiveName,
                    $proposedIsDefault,
                    $effectivePosition,
                    null
                );

                $updateStatement->execute([
                    'redmine_priority_id' => $newPriorityId,
                    'migration_status' => 'CREATION_SUCCESS',
                    'notes' => null,
                    'proposed_redmine_name' => $effectiveName,
                    'proposed_is_default' => normalizeBooleanDatabaseValue($proposedIsDefault),
                    'proposed_redmine_position' => $effectivePosition,
                    'automation_hash' => $automationHash,
                    'mapping_id' => $mappingId,
                ]);

                printf(
                    "  [created] Jira priority %s (%s) -> Redmine priority #%d.%s",
                    $jiraName ?? $jiraId,
                    $jiraId,
                    $newPriorityId,
                    PHP_EOL
                );

                $successCount++;
            } catch (Throwable $exception) {
                $errorMessage = ExtendedApi::summarizeError($exception);
                $automationHash = computePriorityAutomationStateHash(
                    null,
                    'CREATION_FAILED',
                    $effectiveName,
                    $proposedIsDefault,
                    $effectivePosition,
                    $errorMessage
                );

                $updateStatement->execute([
                    'redmine_priority_id' => null,
                    'migration_status' => 'CREATION_FAILED',
                    'notes' => $errorMessage,
                    'proposed_redmine_name' => $effectiveName,
                    'proposed_is_default' => normalizeBooleanDatabaseValue($proposedIsDefault),
                    'proposed_redmine_position' => $effectivePosition,
                    'automation_hash' => $automationHash,
                    'mapping_id' => $mappingId,
                ]);

                printf(
                    "  [failed] Jira priority %s (%s): %s%s",
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

    printf("[%s] Starting push phase (manual priority checklist)...%s", Clock::atom(), PHP_EOL);

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
        $proposedPosition = normalizeInteger($priority['proposed_redmine_position'] ?? null, 1);
        $notes = $priority['notes'] ?? null;

        printf(
            "  - Jira priority: %s (ID: %s)%s",
            $jiraName ?? '[missing name]',
            $jiraId,
            PHP_EOL
        );
        printf(
            "    Proposed Redmine name: %s | Should be default: %s | Proposed order: %s%s",
            $proposedName ?? ($jiraName ?? 'n/a'),
            formatBooleanForDisplay($proposedIsDefault),
            formatIntegerForDisplay($proposedPosition),
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

    printf("  Manual acknowledgement recorded. Remember to update redmine_priority_id, proposed_redmine_position, and migration_status once the priorities exist in Redmine.%s", PHP_EOL);
}

/**
 * @return array<int, array<string, mixed>>
 */
function fetchPrioritiesReadyForCreation(PDO $pdo): array
{
    $sql = <<<SQL
        SELECT
            map.mapping_id,
            map.jira_priority_id,
            map.jira_priority_name,
            map.proposed_redmine_name,
            map.proposed_is_default,
            map.proposed_redmine_position,
            map.notes
        FROM migration_mapping_priorities map
        WHERE map.migration_status = 'READY_FOR_CREATION'
        ORDER BY
            map.proposed_redmine_position IS NULL,
            map.proposed_redmine_position,
            map.jira_priority_name IS NULL,
            map.jira_priority_name,
            map.jira_priority_id
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

function formatIntegerForDisplay(?int $value): string
{
    if ($value === null) {
        return 'n/a';
    }

    return (string)$value;
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

function extractCreatedPriorityId(mixed $decoded): int
{
    if (is_array($decoded)) {
        if (isset($decoded['enumeration']) && is_array($decoded['enumeration']) && isset($decoded['enumeration']['id'])) {
            return (int)$decoded['enumeration']['id'];
        }

        if (isset($decoded['issue_priority']) && is_array($decoded['issue_priority']) && isset($decoded['issue_priority']['id'])) {
            return (int)$decoded['issue_priority']['id'];
        }

        if (isset($decoded['id'])) {
            return (int)$decoded['id'];
        }
    }

    throw new RuntimeException('Unable to determine the new Redmine priority ID from the extended API response.');
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
    echo "      --use-extended-api  Push new priorities through the redmine_extended_api plugin." . PHP_EOL;
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
